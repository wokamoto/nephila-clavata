=== Nephila clavata ===
Contributors: wokamoto, megumithemes, hideokamoto, amimotoami
Donate link: https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=9S8AJCY7XB8F4&lc=JP&item_name=WordPress%20Plugins&item_number=wp%2dplugins&currency_code=JPY&bn=PP%2dDonationsBF%3abtn_donate_SM%2egif%3aNonHosted
Tags: admin, amazon, aws, media, mirror, s3, uploads
Requires at least: 3.5
Tested up to: 4.5.3
Stable tag: 0.2.5

Allows you to mirror your WordPress media uploads over to Amazon S3 for storage and delivery.

== Description ==

This WordPress plugin allows you to use Amazon's Simple Storage Service to host your media for your WordPress powered blog.

**PHP libraries are using [AWS SDK for PHP 2](http://aws.amazon.com/sdkforphp2/ "AWS SDK for PHP 2"). PHP5.3 or later Required.**

= Localization =
"Nephila clavata" has been translated into languages. Our thanks and appreciation must go to the following for their contributions:

* Japanese (ja) - [OKAMOTO Wataru](http://dogmap.jp/ "dogmap.jp") (plugin author)

If you have translated into your language, please let me know.

== Installation ==

1. Upload the entire `nephila-clavata` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

The control panel of Nephila clavata is in 'Settings > Nephila clavata'.

**PHP libraries are using [AWS SDK for PHP 2](http://aws.amazon.com/sdkforphp2/ "AWS SDK for PHP 2"). PHP5.3 or later Required.**

== Frequently Asked Questions ==

none

== Screenshots ==

1. The admin page

== Changelog ==

**0.2.4 - January, 7, 2016**

Add filter for ec2 instance role.
update aws-sdk-php2/2.8.22
thx! https://github.com/hideokamoto

**0.2.3 - June 19, 2015**

modified $content_path, $content_url. thx! https://github.com/torut

**0.2.2 - August 19, 2014**

Support movie file.

**0.2.1 - August 30, 2013**

minor bug fix.

**0.2.0 - June 15, 2013**

If there isn't exists original file, download it from the S3.

**0.1.9 - June 7, 2013**

minor bug fix.

**0.1.6 - May 8, 2013**

Added uninstall script.

**0.1.2 - March 8, 2013**

Some fix.

**0.1.1 - March 7, 2013**

Initial release.
