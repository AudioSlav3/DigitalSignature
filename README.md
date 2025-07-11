# MediaWiki extension for DigitalSignature


The purpose of this extension is to allow digital signing of a MediaWiki page.

## Installation
Add the following to LocalSettings.php:
```php
wfLoadExtension( 'DigitalSignature' );

```
Run the following from the mediawiki's root directory (ex. /var/lib/mediawiki/)
```php
php maintenance/run.php update.php --force
php maintenance/run.php rebuildall.php

```

To ensure changes to signature status are immediate to users it is recommended to prevent page caching by adding the following to LocalSettings.php
```php
$wgParserCacheType = CACHE_NONE;
$wgCachePages = false;

```

## Usage
A method for generating digital signature blocks that are limited to specific users within groups. 
Called like:

```
{{#digital_signature:user=<username>}}

or

{{#digital_signature:group=<groupname>}}
```

Users that do not have signature authority will see

![image](./screenshots/Page_Waiting_on_Signature.jpg)
![image](./screenshots/Page_Signed.jpg)


Those that have signature authority for the page will see

![image](./screenshots/Page_Waiting_on_Signature-ApprovedSigner.jpg)
![image](./screenshots/Page_Signed.jpg)
