# Mailchimp sync operations, including tests.

Sync efforts fall into four categories:

1. Push from CiviCRM to Mailchimp.
2. Pull from Mailchimp to CiviCRM.
3. CiviCRM-fired hooks.
4. Mailchimp-fired Webhooks

Note that a *key difference between push and pull*, other than the direction of
authority, is that mapped interest groups can be declared as being allowed to be
updated on a pull or not. This is useful when Mailchimp has no right to change
that interest group, e.g. a group that you identify with a smart group in
CiviCRM. Typically such groups should be considered internal and therefore
hidden from subscribers at all times.

One of the challenges is to *identify the CiviCRM* contact that a mailchimp
member matches. The code for this is centralised in
`CRM_Mailchimp_Sync::guessContactIdSingle()`, which has tests at
`MailchimpApiMockTest::testGuessContactIdSingle()`. 

Look at the comment block for that test and for the `guessContactIdSingle`
method for details of how contacts are identified. However, this is slow and so
for the bulk operations there's some SQL shortcuts for efficiency which are in the methods:

  - `guessContactIdsBySubscribers`
  - `guessContactIdsByNameAndEmail`
  - `guessContactIdsByUniqueEmail`

## About Names

Mailchimp lists default to having `FNAME` and `LNAME` merge fields to store
first and last names. Some people change/delete these merge fields which makes
things difficult. A common reason is that people wanted a single name field on a
Mailchimp-provided sign-up form. This extension allows for the existance of a
`NAME` merge field. Names found here are split automatically (on spaces) with
the first word becomming the first name and any names following being used as
last names. See unit tests for conditions and handling of blanks.

A 'pull' sync will split the names and then work as if those names
were in FNAME, LNAME merge fields, but only if the FNAME/LNAME fields don't
exist or are both empty.

A 'push' sync will combine the first and last names into a single string and
submit that to the `NAME` merge field, if it exists.


## About email selection.

In order to be subscribed, the contact must:

- have an email available
- not be deceased
- not have `is_opt_out` set
- not have `do_not_email` set

In terms of subscribing people from CiviCRM to Mailchimp, it will use the first
available (i.e. not "on hold") email in this order:

1. Specified bulk email address
2. Primary email address
3. Any other email address


## Tests are provided at different levels.

1. tests in tests/phpunit are designed to be run automatically, e.g. by CI.
   Within this dir there are **unit** tests that are proper unit tests (i.e.
   tests that do not rely on anything other than the system under test; no
   dependencies; no connection to a database or external service.) and
   integration tests that do depend on the CiviCRM database, but none of these
   test use the actual Mailchimp API; none of them require a Mailchimp account.
   Some of the tests mock the Mailchimp API with Prophesy.

2. tests in tests/integration are NOT designed to run automatically and *do*
   require a Mailchimp account, properly configured in CiviCRM. You need to run
   these in a special way. Ideally they would be rewritten to use the `cv`
   program to bootstrap Civi in a way that would work for Wordpress and Drupal,
   currently the boostrapping is hackish. PRs welcome :-)

# Push CiviCRM to Mailchimp Sync for a list.

The Push Sync is done by the `CRM_Mailchimp_Sync` class. The steps are:

1. Fetch required data from Mailchimp for all the list's members.
2. Fetch required data from CiviCRM for all the list's CiviCRM membership group.
3. Add those who are not on Mailchimp, and update those whose details are
   different on CiviCRM compared to Mailchimp.
4. Remove from mailchimp those not on CiviCRM.

The test cases are as follows:

## A subscribed contact not on Mailchimp is added.

`testPushAddsNewPerson()` checks this.

## Name changes to subscribed contacts are pushed except deletions.

    CiviCRM  Mailchimp  Result (at Mailchimp)
    --------+----------+---------------------
    Fred                Fred (added)
    Fred     Fred       Fred (no change)
    Fred     Barney     Fred (corrected)
             Fred       Fred (no change)
    --------+----------+---------------------

This logic is tested by `tests/unit/SyncTest.php`

The collection, comparison and API calls  are tested in
`tests/integration/MailchimpApiIntegrationTest.php`

## Interest changes to subscribed contacts are pushed.

This logic is tested by `tests/unit/SyncTest.php`

The collection, comparison and API calls  are tested in
`tests/integration/MailchimpApiIntegrationTest.php`

## Changes to unsubscribed contacts are not pushed.

This is tested in `tests/integration/MailchimpApiIntegrationTest.php`
in `testPushUnsubscribes()`

## A contact no longer subscribed at CiviCRM should be unsubscribed at Mailchimp.

This is tested in `tests/integration/MailchimpApiIntegrationTest.php`
in `testPushUnsubscribes()`


# Pull Mailchimp to CiviCRM Sync for a list.

The Pull Sync is done by the `CRM_Mailchimp_Sync` class. The steps are:

1. Fetch required data from Mailchimp for all the list's members.
2. Fetch required data from CiviCRM for all the list's CiviCRM membership group.
3. Identify a single contact in CiviCRM that corresponds to the Mailchimp member,
   create a contact if needed.
4. Update the contact with name and interest group changes (only for interests
   that are configured to allow Mailchimp to CiviCRM updates)
5. Remove contacts from the membership group if they are not subscribed at Mailchimp.

The test cases are as follows:

## Test identification of contact by known membership group.

An email from Mailchimp can be used to identify the CiviCRM contact if if
matches among a list of CiviCRM contacts that are in the membership group.

This is done with `SyncIntegrationTest::testGuessContactIdsBySubscribers`

## Test identification of contact by the email only matching one contact.

An email can be matched if it's unique to a particular contact in CiviCRM.

This is done with `SyncIntegrationTest::testGuessContactIdsByUniqueEmail`

## Test identification of contact by email and name match.

An email can be matched along with a first and last name if they all match only
one contact in CiviCRM.

This is done with `SyncIntegrationTest::testGuessContactIdsByNameAndEmail`


## Test that name changes from Mailchimp are properly pulled.

See integration test `testPullChangesName()` and for the name logic see unit test
`testUpdateCiviFromMailchimpContactLogic`.

## Test that interest group changes from Mailchimp are properly pulled.

See integration tests:
- `testPullChangesInterests()` For when the group is configured with update
  permission from Mailchimp to Civi.
- `testPullChangesNonPullInterests()` For when the group is NOT configured with
  update permission.

## Test that contacts unknown to CiviCRM when pulled get added.

See integration test `testPullAddsContact()`.

## Test that contacts not received from Mailchimp but in membership group get removed from membership group.

See integration test `testPullRemovesContacts()`.

# Mailchimp Webhooks

Mailchimp's webhooks are an important part of the system. If they are
functioning correctly then the Pull sync should never need to make any changes.

But they're a nightmare for non-techy users to configure, so now this extension
takes care of them. When you visit the settings page all groups' webhooks are
checked, with errors shown to the user. You can correct a list's webhooks by
editing the CiviCRM group settings. There's a tickbox for doing the webhook
changes which defaults to ticked, and when you save it will ensure everything is
correct.

Tests
- `MailchimpApiMockTest::testCheckGroupsConfig`
- `MailchimpApiMockTest::testConfigureList`



# Posthook used to immediately add/remove  a single person.

If you *add/remove/delete a single contact* from a group that is associated with a
Mailchimp list then the posthook is used to detect this and make the change at
Mailchimp.

There are several cases that this does not cover (and it's therefore of questionable use):

- Smart groups. If you have a smart group of all with last name Flintstone and
  you change someone's name to Flintstone, thus giving them membership of that
  group, this hook will *not* be triggered (@todo test).

- Block additions. If you add more than one contact to a group, the immediate
  Mailchimp updates are not triggered. This is because each contact requires a
  separate API call. Add thousands and this will cause big problems.

If the group you added someone to was synced to an interest at Mailchimp then
the person's membership is checked. If they are, according to CiviCRM in the
group mapped to that lists's membership, then their interests are updated at
Mailchimp. If they are not currently in the membership CiviCRM group then the
interest change is not attempted to be registered with Mailchimp.

See Tests:

- `MailchimpApiMockTest::testPostHookForMembershipListChanges()`
- `MailchimpApiMockTest::testPostHookForInterestGroupChanges()`

Because of these limitations, you cannot rely on this hook to keep your list
up-to-date and will always need to do a CiviCRM to Mailchimp Push sync before
sending a mailing.

# Settings page

The settings page stores details like the API key etc.

However it also serves to check the mapped groups and lists are properly set up. Specifically it:

- Checks that the list still exists on Mailchimp
- Checks that the list's webhook is set and configured exactly.

Warnings are displayed on screen when these settings are wrong and these include
a link to the group's settings page, from which you can auto-configure the list
to the correct settings on Save.

These warnings are tested in `MailchimpApiMockTest::testCheckGroupsConfig()`.


# "Titanics": Duplicate contacts that can't be sunk!

One thing we can't cope with is duplicate contacts. This is now fairly rare
because of the more liberal matching of CiviCRM contacts in version 2.0.

Specifically: an email coming from Mailchimp belonged to several contacts and we
were unable to narrow it down by the names (perhaps there was no name in
Mailchimp).

On *push*, the temporary mailchimp table has NULL in it for these contacts.
Normally we would unsubscribe emails from Mailchimp that are not matched in the
CiviCRM table, but we'll avoid unsubscribing the ones that are NULL.

On *pull*, we will *not* create a contact for NULL `cid_guess` records.

**This means that the contact will stay on Mailchimp unaffected and un-synced by
any sync operations.** They are therefore un-sync-able.

The alternatives?

1. create new contact. Can't do this; it could result in creating a new contact
   on every sync, since every creation would cause the duplication to increase.

2. pick one of the matching contacts at random but they could be different
   people sharing an email so we wouldn't want to merge in any names or
   interests based on the wrong contact.

