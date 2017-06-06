<?php

namespace Mpociot\BotMan\Drivers\Slack;

use Slack\File;
use Slack\RealTimeClient;
use Mpociot\BotMan\Users\User;
use Illuminate\Support\Collection;
use React\Promise\PromiseInterface;
use Mpociot\BotMan\Messages\Incoming\Answer;
use Mpociot\BotMan\Interfaces\DriverInterface;
use Mpociot\BotMan\Messages\Attachments\Audio;
use Mpociot\BotMan\Messages\Attachments\Image;
use Mpociot\BotMan\Messages\Attachments\Video;
use Mpociot\BotMan\Messages\Outgoing\Question;
use Mpociot\BotMan\Drivers\Events\GenericEvent;
use Mpociot\BotMan\Interfaces\DriverEventInterface;
use Mpociot\BotMan\Messages\Incoming\IncomingMessage;
use Mpociot\BotMan\Messages\Outgoing\OutgoingMessage;
use Mpociot\BotMan\Messages\Attachments\File as BotManFile;

class SlackRTMDriver implements DriverInterface
{
    /** @var Collection */
    protected $event;

    /** @var array */
    protected $slackEventData = [];

    /** @var RealTimeClient */
    protected $client;

    /** @var string */
    protected $bot_id;

    const DRIVER_NAME = 'SlackRTM';

    protected $file;

    /**
     * Driver constructor.
     * @param array $config
     * @param RealTimeClient $client
     */
    public function __construct(array $config, RealTimeClient $client)
    {
        $this->event = Collection::make();
        $this->config = Collection::make($config);
        $this->client = $client;

        $this->client->on('_internal_message', function ($type, $data) {
            $this->event = Collection::make($data);
            if ($type !== 'message') {
                $this->slackEventData = [$type, $data];
            } else {
                $this->slackEventData = [];
            }
        });
    }

    /**
     * Connected event.
     */
    public function connected()
    {
        $this->client->getAuthedUser()->then(function ($user) {
            $this->bot_id = $user->getId();
        });
    }

    /**
     * Return the driver name.
     *
     * @return string
     */
    public function getName()
    {
        return self::DRIVER_NAME;
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        return false;
    }

    /**
     * @return bool|DriverEventInterface
     */
    public function hasMatchingEvent()
    {
        if (! empty($this->slackEventData)) {
            list($type, $payload) = $this->slackEventData;
            $event = new GenericEvent($payload);
            $event->setName($type);

            return $event;
        }

        return false;
    }

    /**
     * @param  IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($this->event->get('text'))->setMessage($message);
    }

    /**
     * Convert a Question object into a valid Slack response.
     *
     * @param \Mpociot\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $questionData = $question->toArray();

        $buttons = Collection::make($question->getButtons())->map(function ($button) {
            return array_merge([
                'name' => $button['name'],
                'text' => $button['text'],
                'image_url' => $button['image_url'],
                'type' => $button['type'],
                'value' => $button['value'],
            ], $button['additional']);
        })->toArray();
        $questionData['actions'] = $buttons;

        return $questionData;
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        $messageText = $this->event->get('text');
        $user_id = $this->event->get('user');
        $channel_id = $this->event->get('channel');

        if ($this->event->get('subtype') === 'file_share') {
            $file = Collection::make($this->event->get('file'));
            $message = new IncomingMessage('', '', '');

            if (strstr($file->get('mimetype'), 'image')) {
                $message = new IncomingMessage(Image::PATTERN, $user_id, $channel_id, $this->event);
                $message->setImages([$file->get('permalink')]);
            } elseif (strstr($file->get('mimetype'), 'audio')) {
                $message = new IncomingMessage(Audio::PATTERN, $user_id, $channel_id, $this->event);
                $message->setAudio([$file->get('permalink')]);
            } elseif (strstr($file->get('mimetype'), 'video')) {
                $message = new IncomingMessage(Video::PATTERN, $user_id, $channel_id, $this->event);
                $message->setVideos([$file->get('permalink')]);
            } else {
                $message = new IncomingMessage(\Mpociot\BotMan\Messages\Attachments\File::PATTERN, $user_id, $channel_id, $this->event);
                $message->setFiles([$file->get('permalink')]);
            }

            return [$message];
        }

        return [new IncomingMessage($messageText, $user_id, $channel_id, $this->event)];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return $this->event->has('bot_id') && $this->event->get('bot_id') !== $this->bot_id;
    }

    /**
     * @param string|\Mpociot\BotMan\Messages\Outgoing\Question|IncomingMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return mixed
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = array_merge_recursive([
            'channel' => $matchingMessage->getRecipient(),
            'as_user' => true,
        ], $additionalParameters);

        $this->file = null;

        if ($message instanceof OutgoingMessage) {
            $parameters['text'] = $message->getText();
            $attachment = $message->getAttachment();
            if (! is_null($attachment)) {
                if ($attachment instanceof Image) {
                    $parameters['attachments'] = json_encode([
                        [
                            'title' => $message->getText(),
                            'image_url' => $attachment->getUrl(),
                        ],
                    ]);

                    // else check if is a path
                } elseif ($attachment instanceof BotManFile && file_exists($attachment->getUrl())) {
                    $this->file = (new File())
                        ->setTitle(basename($attachment->getUrl()))
                        ->setPath($attachment->getUrl())
                        ->setInitialComment($message->getText());
                }
            }
        } elseif ($message instanceof Question) {
            $parameters['text'] = '';
            $parameters['attachments'] = json_encode([$this->convertQuestion($message)]);
        } else {
            $parameters['text'] = $message;
        }

        return (is_null($this->file)) ? $parameters : [$matchingMessage->getRecipient()];
    }

    /**
     * @param mixed $payload
     * @return PromiseInterface
     */
    public function sendPayload($payload)
    {
        if (is_null($this->file)) {
            return $this->client->apiCall('chat.postMessage', $payload, false, false);
        }

        return $this->client->fileUpload($this->file, $payload);
    }

    /**
     * @param $message
     * @param array $additionalParameters
     * @param IncomingMessage $matchingMessage
     * @return SlackRTMDriver
     */
    public function replyInThread($message, $additionalParameters, $matchingMessage)
    {
        $additionalParameters['thread_ts'] = ! empty($matchingMessage->getPayload()->get('thread_ts'))
            ? $matchingMessage->getPayload()->get('thread_ts')
            : $matchingMessage->getPayload()->get('ts');

        return $this->reply($message, $matchingMessage, $additionalParameters);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return ! is_null($this->config->get('slack_token'));
    }

    /**
     * Send a typing indicator.
     * @param IncomingMessage $matchingMessage
     * @return mixed
     */
    public function types(IncomingMessage $matchingMessage)
    {
        $channel = null;
        $this->getChannelGroupOrDM($matchingMessage)->then(function ($_channel) use (&$channel) {
            $channel = $_channel;
        });

        if (! is_null($channel)) {
            $this->client->setAsTyping($channel, false);
        }
    }

    /**
     * Retrieve User information.
     * @param IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $user = null;
        $this->client->getUserById($matchingMessage->getSender())->then(function ($_user) use (&$user) {
            $user = $_user;
        });
        if (! is_null($user)) {
            return new User($matchingMessage->getSender(), $user->getFirstName(), $user->getLastName(),
                $user->getUsername());
        }

        return new User($matchingMessage->getSender());
    }

    /**
     * Retrieve Channel information.
     * @param IncomingMessage $matchingMessage
     * @return \Slack\Channel
     */
    public function getChannel(IncomingMessage $matchingMessage)
    {
        return $this->client->getChannelById($matchingMessage->getRecipient());
    }

    /**
     * Retrieve Channel, Group, or DM channel information.
     * @param IncomingMessage $matchingMessage
     * @return \Slack\Channel|\Slack\Group|\Slack\DirectMessageChannel
     */
    public function getChannelGroupOrDM(IncomingMessage $matchingMessage)
    {
        return $this->client->getChannelGroupOrDMByID($matchingMessage->getRecipient());
    }

    /**
     * @return RealTimeClient
     */
    public function getClient()
    {
        return $this->client;
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param $endpoint
     * @param array $parameters
     * @param IncomingMessage $matchingMessage
     * @return \React\Promise\PromiseInterface
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        return $this->client->apiCall($endpoint, $parameters, false, false);
    }

    /**
     * Tells if the stored conversation callbacks are serialized.
     *
     * @return bool
     */
    public function serializesCallbacks()
    {
        return false;
    }
}
