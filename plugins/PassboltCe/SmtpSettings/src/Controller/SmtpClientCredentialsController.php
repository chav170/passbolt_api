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
use Cake\Mailer\Mailer;
use Cake\Mailer\Transport\SmtpTransport;
use Cake\Routing\Router;

class SmtpClientCredentialsController extends AppController
{
    public function authorize()
    {
        $tenantId = Configure::read('E365.client_credentials.tenant_id');
        $clientId = Configure::read('E365.client_credentials.client_id');
        $url = "https://login.microsoftonline.com/$tenantId/adminconsent";
        $params = [
            'client_id' => $clientId,
            'redirect_uri' => Router::url('smtp/client-credentials/callback', full: true),
        ];

        return $this->redirect($url . '?' . http_build_query($params));
    }

    public function callback()
    {
        $adminConsent = $this->getRequest()->getQuery('admin_consent');
        if (!$adminConsent) {
            throw new \Exception('Missing `admin_consent` in query params');
        }
        if ($adminConsent !== 'True') {
            throw new \Exception('Consent denied');
        }

        $this->requestAccessToken();

        return $this->redirect(['action' => 'sendTestEmail']);
    }


    public function sendTestEmail()
    {
        $tokenResponse = Cache::read('client_credentials_token_response', 'test365');
        if (!$tokenResponse) {
            return $this->redirect(['action' => 'authorize']);
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
        $config = [
            'className' => 'Smtp',
            'host' => 'smtp.office365.com',
            'port' => 587,
            'tls' => true,
            'authType' => \Cake\Mailer\Transport\SmtpTransport::AUTH_XOAUTH2,
            'username' => $username,
            'password' => $accessToken,
            'timeout' => 30,
        ];

        $transport = new SmtpTransport($config);

        $mailer = new Mailer(['transport' => $transport]);
        try {
            $result = $mailer
                ->setFrom([$username => $username])
                ->setTo($sendTo)
                ->setSubject(__('SMTP Oauth2 client credentials flow'))
                ->deliver('Test email with client credentials flow');
            debug($result);
            exit;
        } catch (\Exception $e) {
            debug($e);
            exit;
        }
    }

    protected function requestAccessToken(): void
    {
        $tenantId = Configure::read('E365.client_credentials.tenant_id');
        $clientId = Configure::read('E365.client_credentials.client_id');
        $clientSecret = Configure::read('E365.client_credentials.client_secret');

        $http = new Client();
        $response = $http->post(
            "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token",
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://outlook.office365.com/.default',
                'grant_type' => 'client_credentials',
            ]
        );

        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to get token: ' . $response->getStringBody());
        }

        $data = $response->getJson();
        Cache::write(
            'client_credentials_token_response',
            [
                'response' => $data,
                'timestamp' => time(),
            ],
            'test365'
        );
    }
}
