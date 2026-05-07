<p align="center"><img src="./src/icon.svg" width="100" height="100" alt="Feed Me icon"></p>

<h1 align="center">Feed Me for Craft CMS</h1>

Feed Me is a Craft plugin for super-simple importing of content, either once-off or at regular intervals. With support for XML, RSS, ATOM, CSV or JSON feeds, you'll be able to import your content as Entries, Categories, Craft Commerce Products (and variants), and more.

## Requirements

This plugin requires Craft CMS 4.0 or later.

## Installation

You can install this plugin from the Plugin Store or with Composer.

### Installing the Bramble fork

This fork keeps the Composer package name (`craftcms/feed-me`) and Craft plugin handle (`feed-me`) unchanged. That is deliberate: new installs and existing Feed Me installs can use the fork without uninstalling the plugin or losing existing feed definitions.

For shared environments, use the public GitHub fork as a Composer VCS repository. This is the recommended installation method because every developer, CI runner, and deployment target can resolve the same package source.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Made-By-Bramble/feed-me",
      "only": ["craftcms/feed-me"]
    }
  ],
  "require": {
    "craftcms/feed-me": "dev-bramble/5.x-exportable-imports"
  }
}
```

Then run:

```bash
composer update craftcms/feed-me -W
./craft migrate/up --plugin=feed-me
```

Use `dev-bramble/5.x-exportable-imports` for Craft 4 projects. Once a Bramble release tag exists, prefer the matching numeric patch tag, for example `5.14.0.1`, so production installs are pinned to an immutable release.

To override a pre-existing Pixel & Tonic install, make the same `composer.json` change and run the same update command. Do not uninstall Feed Me from Craft. Composer will replace the package code in place, Craft will continue to see the same `feed-me` plugin, and the fork migration will add the export table.

For active local development only, a path repository can symlink a local clone:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "./lib/feed-me",
      "options": {
        "symlink": true
      }
    }
  ],
  "require": {
    "craftcms/feed-me": "dev-bramble/5.x-exportable-imports"
  }
}
```

Only use the path repository when that local clone exists everywhere Composer runs. For production and CI, prefer the VCS repository above.

#### From the Plugin Store

Go to the Plugin Store in your project’s Control Panel and search for “Feed Me”. Then click on the “Install” button in its modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require craftcms/feed-me

# tell Craft to install the plugin
./craft plugin/install feed-me
```

## Customizing Logs

As of version `5.6`/`6.2`, logging is handled by Craft's log component and stored in the database instead of the filesystem.
To log to files (or anywhere else) instead, you can disable the default logging add your own log target:

### config/feed-me.php

```php
<?php
return [
    // disable default logging to database
    'logging' => false,
];
```

### config/app.php

```php
<?php
return [
    'components' => [
        'log' => [
            'monologTargetConfig' => [
                // optionally, omit from Craft's default logs
                'except' => ['feed-me'],
            ],
            
            // add your own log target to write logs to file
            'targets' => [
                [
                    // log to file or STDOUT/STDERR if CRAFT_STREAM_LOG=1 is set
                    'class' => \craft\log\MonologTarget::class,
                    'name' => 'feed-me',
                    'categories' => ['feed-me'],
                    
                    // Don't log request and env vars
                    'logContext' => false,
                    
                    // Minimum level to log
                    'level' => \Psr\Log\LogLevel::INFO,
                ],
            ],
        ],
    ],
];
```

## Resources

- **[Feed Me Plugin Page](https://plugins.craftcms.com/feed-me)** – The official plugin page for Feed Me
- **[Feed Me Documentation](https://docs.craftcms.com/feed-me/v4/)** – The official documentation
- **[Migrating a Website to Craft CMS](https://craftquest.io/courses/migrating-a-website-to-craft-cms/)** – Full video course from CraftQuest that covers Feed Me
