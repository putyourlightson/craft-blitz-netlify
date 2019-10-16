# Blitz Netlify Deployer for Craft CMS

The Netlify Deployer allows the [Blitz](https://putyourlightson.com/plugins/blitz) plugin for [Craft CMS](https://craftcms.com/) to intelligently deploy cached files.

## Usage

Install the deployer using composer.

```
composer require putyourlightson/craft-blitz-netlify
```

Then add the class to the `driverTypes` config setting in `config/blitz.php`.

```
// The driver type classes to add to the plugin’s default driver types.
'deployerTypes' => [
    'putyourlightson\blitz\drivers\deployers\GitDeployer',
    'putyourlightson\blitznetlify\NetlifyDeployer',
],
```

You can then select the deployer and settings either in the control panel or in `config/blitz.php`.

```
// The deployer type to use.
'deployerType' => 'putyourlightson\blitznetlify\NetlifyDeployer',

// The deployer settings.
'deployerSettings' => [
   'clientId' => 'id_abcdefgh1234567890',
   'clientSecret' => 'secret_abcdefgh1234567890',
],
```

## Documentation

Read the documentation at [putyourlightson.com/plugins/blitz](https://putyourlightson.com/plugins/blitz#custom-deployers).

Created by [PutYourLightsOn](https://putyourlightson.com/).
