<?php

/**
 * Helper class to perform actual message forwarding.
 *
 * PHP version 8
 *
 * Copyright (C) Villanova University 2020.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuOwma
 * @package  Messaging
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/FalveyLibraryTechnology/VuOwma/
 */

namespace App;

use App\Entity\Message;
use Laminas\Http\Client;

use function count;

/**
 * Helper class to perform actual message forwarding.
 *
 * @category VuOwma
 * @package  Messaging
 * @author   Demian Katz <demian.katz@villanova.edu>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://github.com/FalveyLibraryTechnology/VuOwma/
 */
class MessageForwarder
{
    /**
     * Blank message in JSON format.
     *
     * @var string
     */
    protected $blankMessage = '{"@context":"https://schema.org/extensions",'
        . '"@type":"MessageCard","themeColor":"0072C6","title":"VuOwma Message",'
        . '"text":""}';

    /**
     * HTTP client
     *
     * @var Client
     */
    protected $client;

    /**
     * Message format to send to webhook
     *
     * @var string
     */
    protected $messageFormat;

    /**
     * URL of the VuOwma public endpoint
     *
     * @var string
     */
    protected $vuowmaUrl;

    /**
     * URL of the Office365/Workflows webhook
     *
     * @var string
     */
    protected $webhookUrl;

    /**
     * Constructor
     *
     * @param array $options Configuration options (base_url, webhook_url, client).
     *
     * @throws \Exception
     */
    public function __construct(array $options = [])
    {
        if (!isset($options['base_url']) || !isset($options['webhook_url'])) {
            throw new \Exception(
                'base_url and webhook_url settings are both required!'
            );
        }
        $this->vuowmaUrl = $options['base_url'];
        $this->webhookUrl = $options['webhook_url'];
        $this->client = $options['client'] ?? new Client();
        $this->messageFormat = trim(strtolower($options['message_format'] ?? 'messagecard'));
    }

    /**
     * Reformat a message if necessary.
     *
     * @param array $message Message to reformat.
     *
     * @return array
     */
    protected function formatMessage($message)
    {
        if ($this->messageFormat === 'messagecard') {
            return $message;
        }
        // If we got this far, we need to translate messagecard to adaptivecard:
        $body = [];
        if (!empty($message['title'])) {
            $body[] = [
                'type' => 'TextBlock',
                'text' => '**' . $message['title'] . '**',
                'size' => 'large',
            ];
        }
        $body[] = [
            'type' => 'TextBlock',
            'text' => $message['text'],
            'wrap' => true,
        ];
        return [
            'type' => 'message',
            'attachments' => [
                [
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'contentUrl' => null,
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.2',
                        'body' => $body,
                        'msteams' => ['width' => 'Full'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Forward locally stored information to the webhook handler.
     *
     * @param Message[] $messages      Pending messages loaded from the database
     * @param ?int      $batch_id      The ID of the batch currently being processed
     * @param array     $unsentBatches An array of batch IDs that should have been
     * sent earlier but which failed for some reason
     *
     * @return void
     */
    public function forward(
        array $messages,
        ?int $batch_id,
        array $unsentBatches
    ): void {
        if (count($messages) === 0 && empty($unsentBatches)) {
            // nothing to do:
            return;
        }
        $firstMessage = reset($messages);
        $firstMessageData = $firstMessage ? $firstMessage->getData() : null;
        $message = json_decode($firstMessageData ?? $this->blankMessage, true);
        if (!isset($message['text'])) {
            $message['text'] = '--no message provided--';
        }
        foreach ($unsentBatches as $unsentBatch) {
            $message['text'] = "Resending previously failed [batch $unsentBatch]("
                . $this->vuowmaUrl . '?batch=' . $unsentBatch . ")  \n"
                . $message['text'];
        }
        if (count($messages) > 1) {
            $message['text'] = count($messages)
                . " log messages in [batch $batch_id](" . $this->vuowmaUrl
                . '?batch=' . $batch_id . ")  \nFirst message: " . $message['text'];
        }
        $this->client->setUri($this->webhookUrl);
        $this->client->setMethod('POST');
        $this->client->setEncType('application/json');
        $this->client->setRawBody(json_encode($this->formatMessage($message)));
        $response = $this->client->send();
        if (!$response->isSuccess()) {
            throw new \Exception(
                "Problem sending message to $this->webhookUrl. Status: "
                . $response->getStatusCode()
                . '. Body: ' . $response->getBody()
            );
        }
    }
}
