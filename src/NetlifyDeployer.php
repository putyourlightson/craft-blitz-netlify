<?php
/**
 * @copyright Copyright (c) PutYourLightsOn
 */

namespace putyourlightson\blitznetlify;

use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\db\Table;
use craft\events\RegisterTemplateRootsEvent;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use League\OAuth2\Client\Grant\AuthorizationCode;
use League\OAuth2\Client\Token\AccessToken;
use putyourlightson\blitz\Blitz;
use putyourlightson\blitz\drivers\deployers\BaseDeployer;
use putyourlightson\blitz\events\RefreshCacheEvent;
use putyourlightson\blitz\helpers\DeployerHelper;
use putyourlightson\blitz\helpers\SiteUriHelper;
use putyourlightson\blitz\records\DriverDataRecord;
use Putyourlightson\OAuth2\Client\Provider\Netlify;
use yii\base\ErrorException;
use yii\base\Event;
use yii\base\InvalidArgumentException;
use yii\web\ForbiddenHttpException;
use ZipArchive;

/**
 * @property array $netlifySiteOptions
 * @property bool $isAuthorized
 * @property mixed $settingsHtml
 */
class NetlifyDeployer extends BaseDeployer
{
    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('blitz', 'Netlify Deployer');
    }

    // Properties
    // =========================================================================

    /**
     * @var array
     */
    public $netlifySites = [];

    /**
     * @var string|null
     */
    public $clientId;

    /**
     * @var string|null
     */
    public $clientSecret;

    /**
     * @var string
     */
    public $deployMessage = 'Blitz auto deploy';

    /**
     * @var AccessToken|null
     */
    private $_accessToken;

    /**
     * @var Netlify|null
     */
    private $_provider;

    /**
     * @var string
     */
    private $_apiUrl = 'https://api.netlify.com/api/v1/';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        // Get access token from DB and create object if found
        $driverDataRecord = DriverDataRecord::findOne(['driver' => self::class]);

        if ($driverDataRecord !== null) {
            $data = Json::decodeIfJson($driverDataRecord->data);

            if (!empty($data['accessToken'])) {
                $this->_accessToken = new AccessToken($data['accessToken']);
            }
        }

        // Register CP template root
        Event::on(View::class, View::EVENT_REGISTER_CP_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['blitz-netlify'] = __DIR__.'/templates/';
            }
        );
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['clientId', 'clientSecret'],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function rules()
    {
        return [
            [['clientId', 'clientSecret', 'deployMessage'], 'required'],
        ];
    }

    /**
     * Deploys site URIs with progress.
     *
     * @param array $siteUris
     * @param callable|null $setProgressHandler
     */
    public function deployUrisWithProgress(array $siteUris, callable $setProgressHandler = null)
    {
        $count = 0;
        $total = 0;
        $label = 'Deploying {count} of {total} files.';

        $temporaryPath = Craft::$app->getPath()->getTempPath().'/blitz-netlify/'.time();
        $deployGroupedSiteUris = [];
        $groupedSiteUris = SiteUriHelper::getSiteUrisGroupedBySite($siteUris);

        foreach ($groupedSiteUris as $siteId => $siteUris) {
            $siteUid = Db::uidById(Table::SITES, $siteId);

            if ($siteUid === null) {
                continue;
            }

            if (empty($this->netlifySites[$siteUid]) || empty($this->netlifySites[$siteUid]['siteId'])) {
                continue;
            }

            $deployGroupedSiteUris[$siteUid] = $siteUris;
            $total += count($siteUris);
        }

        if (is_callable($setProgressHandler)) {
            $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
            call_user_func($setProgressHandler, $count, $total, $progressLabel);
        }

        foreach ($deployGroupedSiteUris as $siteUid => $siteUris) {
            $netlifySiteId = $this->netlifySites[$siteUid]['siteId'];
            $path = $temporaryPath.'/'.$netlifySiteId.'/';
            $filename = 'deploy.zip';

            $zip = new ZipArchive();
            $zip->open($path.$filename, ZipArchive::CREATE);

            foreach ($siteUris as $siteUri) {
                $count++;
                $progressLabel = Craft::t('blitz', $label, ['count' => $count, 'total' => $total]);
                call_user_func($setProgressHandler, $count, $total, $progressLabel);

                $value = Blitz::$plugin->cacheStorage->get($siteUri);

                if (empty($value)) {
                    continue;
                }

                $filePath = FileHelper::normalizePath($path.$siteUri->uri.'/index.html');
                $this->_save($value, $filePath);

                $zip->addFile($filePath, $siteUri->uri.'/index.html');
            }

            $zip->close();

            // Parse twig tags in the deploy message
            $deployMessage = Craft::$app->getView()->renderString($this->deployMessage);

            Craft::createGuzzleClient()
                ->post($this->_apiUrl.'sites/'.$netlifySiteId.'/deploys', [
                    'headers' => [
                        'Authorization' => 'Bearer '.$this->_accessToken->getToken(),
                        'Content-Type' => 'application/zip',
                    ],
                    'query' => [
                        'title' => $deployMessage,
                    ],
                    'body' => fopen($path.$filename, 'r'),
                ]);
        }
    }

    /**
     * Returns whether the deployer is authorized.
     *
     * @return bool
     */
    public function getIsAuthorized(): bool
    {
        return $this->_accessToken !== null;
    }

    /**
     * Returns the Netlify sites.
     *
     * @return array
     */
    public function getNetlifySiteOptions(): array
    {
        $options = [[
            'label' => Craft::t('blitz', 'None'),
            'value' => '',
        ]];

        if ($this->_accessToken === null) {
            return $options;
        }

        $response = Craft::createGuzzleClient()
            ->get($this->_apiUrl.'sites', [
                'query' => ['access_token' => $this->_accessToken->getToken()]
            ]);

        $netlifySites = Json::decodeIfJson($response->getBody()->getContents());

        foreach ($netlifySites as $netlifySite) {
            $options[] = [
                'label' => $netlifySite['name'].' ('.$netlifySite['url'].')',
                'value' => $netlifySite['id'],
            ];
        }

        return $options;
    }

    /**
     * @inheritdoc
     */
    public function test(): bool
    {
        $request = Craft::$app->getRequest();

        // Check for actions
        if ($request->get('netlify') == 'authorize') {
            $this->_oauthConnect();
        }
        elseif ($request->get('netlify') == 'authorized') {
            Craft::$app->getSession()->setNotice(Craft::t('blitz', 'Netlify account successfully authorized.'));
        }

        // Check if a code was sent
        $code = $request->get('code');

        if ($code !== null) {
            $this->_oauthCallback($code);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('blitz-netlify/settings', [
            'deployer' => $this,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the provider.
     *
     * @return Netlify
     */
    private function _getProvider()
    {
        if ($this->_provider === null) {
            $this->_provider = new Netlify([
                'clientId' => Craft::parseEnv($this->clientId),
                'clientSecret' => Craft::parseEnv($this->clientSecret),
                'redirectUri' => UrlHelper::cpUrl('settings/plugins/blitz'),
            ]);
        }

        return $this->_provider;
    }

    /**
     * Connects to the OAuth provider.
     */
    private function _oauthConnect()
    {
        $provider = $this->_getProvider();

        $authorizationUrl = $provider->getAuthorizationUrl();

        // Store state to verify OAuth callback (must come after call to `getAuthorizationUrl()`)
        Craft::$app->getSession()->set('blitz.netlify.state', $provider->getState());

        Craft::$app->getResponse()->redirect($authorizationUrl);
    }

    /**
     * Callback from the OAuth provider.
     *
     * @param string $code
     */
    private function _oauthCallback(string $code)
    {
        $provider = $this->_getProvider();

        // Verify state
        $state = Craft::$app->getRequest()->get('state');

        if (empty($state) || $state != Craft::$app->getSession()->get('blitz.netlify.state')) {
            throw new ForbiddenHttpException('Invalid state.');
        }

        $accessToken = $provider->getAccessToken(new AuthorizationCode(), [
            'code' => $code,
        ]);

        // Save access token data to DB
        $values = ['driver' => self::class];
        $driverDataRecord = DriverDataRecord::findOne($values);

        if ($driverDataRecord === null) {
            $driverDataRecord = new DriverDataRecord($values);
        }

        $driverDataRecord->data = ['accessToken' => $accessToken];
        $driverDataRecord->save();

        Craft::$app->getResponse()->redirect('blitz?netlify=authorized#deployment');
    }

    /**
     * Saves a value to a file path.
     *
     * @param string $value
     * @param string $filePath
     */
    private function _save(string $value, string $filePath)
    {
        try {
            FileHelper::writeToFile($filePath, $value);
        }
        catch (ErrorException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
        catch (InvalidArgumentException $e) {
            Blitz::$plugin->log($e->getMessage(), [], 'error');
        }
    }
}
