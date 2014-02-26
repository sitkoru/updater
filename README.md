Usage:
===========

Add 
```js
"sitkoru/updater": "dev-master"
```
to require section of your composer.json

Enable module in console app config:
```php
    'modules'=>[
    ...
        'updater'   => [
            'class'           => \sitkoru\updater\Module::className(), //module class name
            'path'            => dirname(dirname(__DIR__)), //path to app
            'versionFilePath' => 'version.php', //path to file to store version. Include this file in your app.
            'currentVersion'  => defined('APP_VERSION') ? APP_VERSION : 0.0, //current version. If empty, updater will try to get it from versionFile
            'versionConstant' => 'APP_VERSION', //constant name to store version
            'releasePrefix'   => "origin/release-", //prefix to identify release branches in git
            'assetsCommands'  => [ //commands for publish assets
                "./yii asset/compress app/config/main.assets.php app/config/bundles.php" //for example
            ],
            'customCommands'  => [ //commands for custom operations, like starting daemons or something else
                "nodejs bin/chat.js" //for example
            ]
        ],
    ...
    ]
```

Other options you can see in Module.php

Run
```bash
./yii updater/release
```
and follow instructions =)

Notice:
===========
Updater override yii's migrate functions to mark migrations with app version. Some advices:

1. Don't use it in dev mode =)

2. Don't use ./yii migrate on production

3. If you need to manually apply migrations, use ./yii updater/migrations/up 0 1.1 //replace 1.1 with real version