PHPUnit Unit Tests
==================

These tests aim to be proper unit tests and therefore they should not depend on
any system apart from this; they do not depend on having the database and they
do not depend on Mailchimp.

You need [phpunit](https://phpunit.de/manual/current/en/installation.html) to
run these tests. Example usage

You **must** start from the docroot of your site. e.g.

    $ cd /var/www/my.civicrm.website/

Run the tests. A dot means a successful test pass.

    $ phpunit.phar civicrm_extensions_dir/uk.co.vedaconsulting.mailchimp/tests/unit/
