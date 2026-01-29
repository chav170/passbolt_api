<?php
declare(strict_types=1);

/**
 * Passbolt ~ Open source password manager for teams
 * Copyright (c) Passbolt SA (https://www.passbolt.com)
 *
 * Licensed under GNU Affero General Public License version 3 of the or any later version.
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Passbolt SA (https://www.passbolt.com)
 * @license       https://opensource.org/licenses/AGPL-3.0 AGPL License
 * @link          https://www.passbolt.com Passbolt(tm)
 * @since         3.8.0
 */
namespace Passbolt\SmtpSettings\Controller;

use App\Controller\AppController;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;

class SmtpGraphController extends AppController
{
    public function sendTestEmail()
    {
        $tokenResponse = Cache::read('graph_token_response', 'test365');
        if (!$tokenResponse) {
            $this->requestAccessToken();
            $tokenResponse = Cache::read('graph_token_response', 'test365');
        }

        try {
            $response = $this->requestSendEmail();
            debug('Response code: ' . $response->getStatusCode());
            debug('Response body: ' . $response->getStringBody());
            exit;
        } catch (\Exception $e) {
            debug($e);
            exit;
        }
    }

    protected function requestAccessToken(): void
    {
        $tenantId = Configure::read('E365.graph.tenant_id');
        $clientId = Configure::read('E365.graph.client_id');
        $clientSecret = Configure::read('E365.graph.client_secret');

        $http = new Client();
        $response = $http->post(
            "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token",
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://graph.microsoft.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to get token: ' . $response->getStringBody());
        }

        $data = $response->getJson();
        Cache::write(
            'graph_token_response',
            [
                'response' => $data,
                'timestamp' => time(),
            ],
            'test365'
        );
    }

    protected function requestSendEmail(): Response
    {
        $tokenResponse = Cache::read('graph_token_response', 'test365');
        if (!$tokenResponse) {
            throw new \RuntimeException('Missing token response');
        }
        $accessToken = $tokenResponse['response']['access_token'] ?? null;
        if (!$accessToken) {
            throw new \Exception('Invalid response data');
        }
        $expirationTime = $tokenResponse['timestamp'] + $tokenResponse['response']['expires_in'];
        if (time() > $expirationTime) {
            $this->requestAccessToken();
            $tokenResponse = Cache::read('client_credentials_token_response', 'test365');
            $accessToken = $tokenResponse['response']['access_token'] ?? null;
            if (!$accessToken) {
                throw new \Exception('Invalid response data');
            }
        }

        $username = Configure::read('E365.transport.username');
        $sendTo = Configure::read('E365.transport.send_to');

        $http = new Client([
            'headers' => [
                'Authorization' => 'Bearer ' . $accessToken,
                'Content-Type'  => 'application/json',
            ],
        ]);

        return $http->post(
            "https://graph.microsoft.com/v1.0/users/$username/sendMail",
            json_encode([
                'message' => [
                    'subject' => 'SMTP Oauth2 Graph flow',
                    'body' => [
                        'contentType' => 'Text',
                        // @todo: will need to render the email first to add the content, maybe using DebugTransport
                        'content' => 'Test email with client credentials flow',
                    ],
                    'toRecipients' => [
                        [
                            'emailAddress' => [
                                'address' => $sendTo,
                            ],
                        ],
                    ],
                ],
                'saveToSentItems' => true,
            ])
        );
    }
}
