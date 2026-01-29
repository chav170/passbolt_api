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

class SmtpAuthorizationCodeController extends AppController
{
    public function authorize()
    {
        $tenantId = Configure::read('E365.auth_code.tenant_id');
        $clientId = Configure::read('E365.auth_code.client_id');
        $url = "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/authorize";
        $params = [
            'client_id' => $clientId,
            'response_type' => 'code',
            'response_mode' => 'query',
            'redirect_uri' => Router::url('smtp/authorization-code/callback', full: true),
            'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
        ];

        return $this->redirect($url . '?' . http_build_query($params));
    }

    public function callback()
    {
        $code = $this->getRequest()->getQuery('code');
        if (!$code) {
            throw new \Exception('Missing `code` in query params');
        }

        $tenantId = Configure::read('E365.auth_code.tenant_id');
        $clientId = Configure::read('E365.auth_code.client_id');
        $clientSecret = Configure::read('E365.auth_code.client_secret');

        $http = new Client();
        $response = $http->post(
            "https://login.microsoftonline.com/$tenantId/oauth2/v2.0/token",
            [
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => Router::url('smtp/authorization-code/callback', full: true),
            ]
        );

        if (!$response->isOk()) {
            throw new \RuntimeException('Failed to get token: ' . $response->getStringBody());
        }

        $data = $response->getJson();
        Cache::write(
            'auth_code_token_response',
            [
                'response' => $data,
                'timestamp' => time(),
            ],
            '_cake_model_'
        );

        return $this->redirect(['action' => 'sendTestEmail']);
    }


    public function sendTestEmail()
    {
        $tokenResponse = Cache::read('auth_code_token_response', '_cake_model_');
        if (!$tokenResponse) {
            return $this->redirect(['action' => 'authorize']);
        }

        $accessToken = $tokenResponse['response']['access_token'] ?? null;
        if (!$accessToken) {
            throw new \Exception('Invalid response data');
        }

        $expirationTime = $tokenResponse['timestamp'] + $tokenResponse['response']['expires_in'];
        if (time() > $expirationTime) {
            return $this->redirect(['action' => 'authorize']);
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
        $result = $mailer
            ->setFrom([$username => $username])
            ->setTo($sendTo)
            ->setSubject(__('SMTP Oauth2 authorization code flow'))
            ->deliver('Test email with authorization code flow');
        debug($result);
        exit;
    }
}
