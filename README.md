# Git Flow Deployment

> Deploy your projects with ease – simple setup with minimal configuration 

This script provides continuous deployment for git projects. It is compliant with git flow model and all inferior models by default. It is simply configurable and supports multiple projects and hooks.

## Requirements

 - Your git project on one of the following servers
   - [GitHub](https://github.com/)
   - [Bitbucket](https://bitbucket.org/)
   - [Gitlab](https://about.gitlab.com/) (not supported yet)
 - [PHP 5.6+](http://php.net/downloads.php)
 - [Bash](https://www.gnu.org/software/bash/)
 - Public domain (for webhook payload)

## Installation

1) Download GFD into non-public directory

   ```
   git clone https://github.com/InternetGuru/gfd.git /var/local/gfd
   ```
   
   Tip: GFD must be in PHP allowed script path (e.g. ``open_basedir = /var/local/gfd``) – for more information see [open_basedir](http://php.net/manual/en/ini.core.php#ini.open-basedir)
   
1) Create public domain for webhook e.g.

   ```
   mkdir /var/www/domains/deploy.example.com
   ```
   
1) Create public symlink leading to GFD index

   ```
   ln -s /var/local/gfd/index.php /var/www/domains/deploy.example.com
   ```

## Easy Setup

### 1) Setting up your server

1) Go to GFD project directory on your server e.g.

   ```
   cd /var/local/gfd
   ```

1) Create project ``SECRET`` e.g.
   
   ```
   cat /dev/urandom | tr -dc 'a-zA-Z0-9' | fold -w ${1:-32} | head -n 1 > SECRET
   ```

1) Choose your ``projectid`` e.g. ``fancyprojectid``

1) Create (and edit) project settings

   ```
   cp config.yml{.example,}
   ```

   Inside ``config.yml`` replace ``[projectid]`` by yours
   
1) Optionally create one or more GFD hooks

   Hooks are standalone scripts located in ``hooks`` directory and named according to following pattern ``[projectid]-(pre|post)-(fetch|checkout|sync)`` e.g. ``fancyprojectid-post-sync``.


### 2) Setting up your project 

1) Create webhook e.g. ``Settings -> Webhooks``

1) Set payload URL e.g ``https://deploy.example.com?projectid=fancyprojectid``

1) Set secret (from file ``SECRET``)
   
   Note: Bitbucket [does not support secret header](https://bitbucket.org/site/master/issues/12195/webhook-hmac-signature-security-issue) – as workaround is recommend to use https and add your secret in url e.g. ``https://deploy.example.com?projectid=fancyprojectid&secret=[secret]``

1) Check only ‘on push event trigger‘

## Maintainers

-  Pavel Petržela pavel.petrzela@internetguru.cz
-  Jiří Pavelka jiri.pavelka@internetguru.cz

## Contributing

Pull requests are welcome, don't hesitate to contribute.

## Donation

If you find this program useful, please **send a donation** to its developers to support their work. If you use this program at your workplace, please suggest that the company make a donation. We appreciate contributions of any size. Donations enable us to spend more time working on this package, and help cover our infrastructure expenses.

If you’d like to make a donation of any value, please send it to the following PayPal address:

[PayPal Donation](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=V7AZWMLS8QWAY)

Since we aren’t a tax-exempt organization, we can’t offer you a tax deduction. But for all donations over 50 USD, we’d be happy to recognize your contribution on this README file (including manual page) for the next release.

We are also happy to consider making particular improvements or changes, or giving specific technical assistance, in return for a substantial donation over 100 USD. If you would like to discuss this possibility, write us at info@internetguru.cz.

Another possibility is to pay a software maintenance fee. Again, write us about this at info@internetguru.cz to discuss how much you want to pay and how much maintenance we can offer in return.

Thanks for your support!

### Donors

- [Faculty of Information Technology, CTU Prague](https://www.fit.cvut.cz/en)

## License

GNU General Public License version 3, see the [LICENSE file](LICENSE).
