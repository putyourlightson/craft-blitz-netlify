# Blitz Netlify Deployer for Craft CMS

The Netlify Deployer allows the [Blitz](https://putyourlightson.com/plugins/blitz) plugin for [Craft CMS](https://craftcms.com/) to deploy cached files directly to Netlify sites.

> While the Netlify Deployer provides a quick and easy setup, the Git Deployer that ships with Blitz is the recommended way of deploying full websites, including images and other static assets, to Netlify sites.

## Usage

Install the deployer using composer.

```
composer require putyourlightson/craft-blitz-netlify
```

Then add the class to the `driverTypes` config setting in `config/blitz.php`.

```
// The deployer type classes to add to the plugin’s default deployer types.
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

Read the documentation at [putyourlightson.com/plugins/blitz](https://putyourlightson.com/plugins/blitz#remote-deployers).

Created by [PutYourLightsOn](https://putyourlightson.com/).
