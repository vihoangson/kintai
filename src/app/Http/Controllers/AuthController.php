<?php namespace App\Http\Controllers;

use GuzzleHttp\Client as GuzzleHttpClient;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericProvider;
use League\OAuth2\Client\Token\AccessToken;
use Symfony\Component\Security\Core\Encoder\BCryptPasswordEncoder;
use Tymon\JWTAuth\Exceptions\JWTException;
use Zend\Mail\Message;
use Zend\Mail\Transport\Smtp as SmtpTransport;
use Zend\Mail\Transport\SmtpOptions;
use Zend\Mime\Message as MimeMessage;
use Zend\Mime\Part as MimePart;
use Chaos\Common\Exceptions\ValidateException;

/**
 * Class AuthController
 * @author ntd1712
 */
class AuthController extends Controller
{
    /**
     * Single-page application
     * For route:cache only
     */
    public function spa()
    {
        return view($this->getConfig()->get('app.theme') . '.app', ['config' => $this->getConfig()]);
    }

    /**
     * For route:cache only
     */
    public function ping() {}

    /** {@inheritdoc} */
    public function oauth2(\Request $request, $returnOnly = false)
    {
        // get resource owner
        $provider = new GenericProvider($this->getConfig()->get('auth.drivers.oauth2'));
        $provider->setHttpClient(new GuzzleHttpClient(['verify' => false]));

        switch ($grant = $request::get('grant'))
        {
            case 'access_token':
                $accessToken = new AccessToken($request::all() + ['expires_in' => $this->getConfig()->get('cookie.expires') * 60]);

                if ($accessToken->hasExpired())
                {
                    throw new IdentityProviderException('token_expired', 0, []);
                }
                break;
            case 'client_credentials':
                $accessToken = $provider->getAccessToken($grant);
                break;
            case 'password':
                $accessToken = $provider->getAccessToken($grant, [
                    'username' => $request::get('email'),
                    'password' => $request::get('password')
                ]);
                break;
            case 'refresh_token':
                try
                {
                    $accessToken = $provider->getAccessToken($grant, [
                        'refresh_token' => $request::get('refresh_token')
                    ]);
                }
                catch (IdentityProviderException $e) {}
                break;
            default:
        }

        if (!isset($accessToken))
        {
            if (null === $request::get('code'))
            {
                $authorizationUrl = $provider->getAuthorizationUrl();
                \Session::set('oauth2state', $provider->getState());
                \Session::save();

                return \Redirect::away($authorizationUrl);
            }
            elseif (is_blank($request::get('state')) ||
                urldecode($request::get('state')) !== urldecode(\Session::pull('oauth2state')))
            {
                \Session::save();
                throw new IdentityProviderException('invalid_state', 0, []);
            }

            $accessToken = $provider->getAccessToken('authorization_code', [
                'code' => urldecode($request::get('code'))
            ]);
        }

        $resourceOwner = $provider->getResourceOwner($accessToken)->toArray()['data'][0];
        /** @var \Account\Entities\User $entity */
        $entity = $this->getService('User')->getByName($resourceOwner['userName']);

        if (null === $entity)
        {
            $response = $this->getService('User')->create([
                'Name' => $resourceOwner['userName'],
                'Email' => $resourceOwner['emailAddress'],
                'Profile' => [
                    'DisplayName' => $resourceOwner['realName'] ?: $resourceOwner['loginName'],
                    'Age' => $resourceOwner['age'],
                    'Sex' => $resourceOwner['sex']
                ],
                'OpenId' => $resourceOwner['userId'],
                'ModifiedBy' => $resourceOwner['userName']
            ] + $this->getRequest());
            $entity = $response['data'];
        }

        // prepare data
        $user = $entity->toSimpleArray();
        $user['Roles'] = $user['Permissions'] = [];

        \JWTAuth::manager()->getPayloadFactory()->setTTL(($accessToken->getExpires() - time()) / 60);
        $token = \JWTAuth::setIdentifier('Id')->fromUser($entity, ['res' => $user]);

        // save into session
        \Session::set('accessToken', $accessToken);
        \Session::set('locale', $request::get('language', @$user['Locale']));
        \Session::set('loggedName', $user['Name']);
        \Session::set('loggedUser', $user);
        \Session::save();

        // bye!
        if ($returnOnly)
        {
            return compact('token');
        }

        return \Redirect::away($this->getConfig()->get('app.url') . "/#/oauth2?s={$token}&r={$accessToken->getRefreshToken()}");
    }

    /**
     * The "login" action
     *
     * @param   \Request $request
     * @return  \Symfony\Component\HttpFoundation\Response
     * @throws  JWTException
     */
    public function postLogin(\Request $request)
    {
        // are we logging out, or doing something else?
        if (true === (bool)$request::get('logout'))
        {
            return $this->postLogout();
        }
        elseif (true === (bool)$request::get('identity'))
        {
            return $this->postIdentity($request);
        }
        elseif (true === (bool)$request::get('reset'))
        {
            return $this->postReset($request);
        }

        // do some checks
        $validator = $this->getValidationFactory()->make($request::all(), [
            'email' => 'required|email|max:255', 'password' => 'required'
        ]);

        if ($validator->fails())
        {
            throw new ValidateException(implode('; ', $validator->getMessageBag()->all()));
        }

        switch ($this->getConfig()->get('auth.default'))
        {
            case 'oauth2':
                return $this->oauth2($request, true);
            default:
        }

        /** @var \Account\Entities\User $entity */
        $entity = $this->getService('User')->getByEmail($request::get('email'));

        if (null === $entity || !\Hash::check($request::get('password'), $entity->getPassword()))
        {
            throw new ValidateException('Invalid credentials');
        }

        // prepare data
        $user = $entity->toSimpleArray();
        $user['Roles'] = $user['Permissions'] = [];

        if (0 !== count($roles = $entity->getRoles()))
        {   /** @var \Account\Entities\UserRole $userRole */
            foreach ($roles as $userRole)
            {
                $user['Roles'][strtolower($userRole->getRole()->getName())] = $userRole->getRole()->getId();

                if (0 !== count($permissions = $userRole->getRole()->getPermissions()))
                {   /** @var \Account\Entities\Permission $permission */
                    foreach ($permissions as $permission)
                    {
                        $user['Permissions'][strtolower($permission->getName())] = $permission->getId();
                    }
                }
            }
        }

        $token = \JWTAuth::setIdentifier('Id')->fromUser($entity, ['res' => $user]);

        // save into session
        \Session::set('locale', $request::get('locale', @$user['Locale']));
        \Session::set('loggedName', $user['Name']);
        \Session::set('loggedUser', $user);
        \Session::save();

        // bye!
        return compact('token');
    }

    /**
     * The "logout" action
     *
     * @return  array
     */
    public function postLogout()
    {
        try
        {
            \JWTAuth::invalidate(\JWTAuth::getToken());
        }
        catch (JWTException $e) {}

        \Session::forget('locale');
        \Session::forget('loggedName');
        \Session::forget('loggedUser');
        \Session::save();

        return ['success' => true];
    }

    /**
     * The "identity (forgot password)" action
     *
     * @param   \Request $request
     * @return  array
     * @throws  JWTException
     */
    public function postIdentity(\Request $request)
    {
        // do some checks
        $validator = $this->getValidationFactory()->make($request::all(), [
            'email' => 'required|email|max:255'
        ]);

        if (!$validator->fails())
        {   /** @var \Account\Entities\User $entity */
            $entity = $this->getService('User')->getByEmail($request::get('email'));
        }

        if (!isset($entity))
        {
            throw new ValidateException('Invalid credentials');
        }

        // prepare data for email
        $html = new MimePart(nl2br(strtr(\Lang::get('auth.identity_tpl'), [
            ':name' => $entity->getName(),
            ':link' => $this->getConfig()->get('app.url') . '/#/reset?k=' . base64_encode($entity->getEmail())
        ])));
        $html->type = 'text/html';
        $body = new MimeMessage;
        $body->addPart($html);

        $message = new Message;
        $message
            ->addTo($entity->getEmail(), $entity->getName())
            ->addFrom($this->getConfig()->get('mail.username'), \Lang::get('auth.identity_sender'))
            ->setSubject(\Lang::get('auth.identity_subject'))
            ->setBody($body);

        $transport = new SmtpTransport(new SmtpOptions([
            'name' => 'mail.biziwave.net',
            'host' => $this->getConfig()->get('mail.host'),
            'port' => (int)$this->getConfig()->get('mail.port'),
            'connection_config' => [
                'username' => $this->getConfig()->get('mail.username'),
                'password' => $this->getConfig()->get('mail.password')
            ],
            'connection_class' => 'login'
        ]));
        $transport->send($message);

        // bye!
        return ['success' => true];
    }

    /**
     * The "reset (password)" action
     *
     * @param   \Request $request
     * @return  array
     * @throws  JWTException
     */
    public function postReset(\Request $request)
    {
        // do some checks
        $email = base64_decode($request::get('email'));
        $validator = $this->getValidationFactory()->make(['email' => $email] + $request::all(), [
            'email' => 'required|email|max:255', 'password' => 'required'
        ]);

        if (!$validator->fails())
        {   /** @var \Account\Entities\User $entity */
            $entity = $this->getService('User')->getByEmail($email);
        }

        if (!isset($entity))
        {
            throw new ValidateException('Invalid credentials');
        }

        // prepare data
        $entity->setPassword((new BCryptPasswordEncoder(10))->encodePassword($request::get('password'), null));
        $affectedRows = $this->getService('User')->getRepository()->update($entity);

        // bye!
        return ['success' => 0 != $affectedRows];
    }

    /**
     * The "register" action
     *
     * @return  array
     */
    public function postRegister()
    {
        return $this->getService('User')->create($this->getRequest());
    }
}