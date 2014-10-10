uk.co.vedaconsulting.mailchimp
==============================

The new extension builds on the existing work done by the science gallery, adding the ability to pick the Mailchimp List the CiviCRM group should be integrated to aswell as making the entire process a much simpler one to setup.

For each Mailchimp list that you want to integrate, you set up a CiviCRM group.
This will control the subscribers in that list. Add contacts to the group in
CiviCRM and after pushing the sync button, those will be subscribed to your Mailchimp
list. Remove contacts from the group in CiviCRM and they sync will unsubscribe
them at Mailchimp. If anyone clicks an unsubscribe link in a Mailchimp email,
they are automatically removed from your CiviCRM group.

Additionally, if you use Mailchimp's "Interest Groupings", you can map particular
Mailchimp groups to a CiviCRM group. You can choose whether this is a group that the
subscriber can edit (using Mailchimp's forms), or not.

So if you have a list of fundraisers you might use an interest grouping called
"Interests" and give subscribers options like "Shaking tins", "Door knocking",
"Climbing mountains".  Each of these can be mapped to a CiviCRM group (if you
so choose) and membership of these groups will be updated.

Alternatively, what if you have groups in CiviCRM like "major donor" or
"miserly meanie" that you want to use to segment your mailings but you don't
want subscribers seeing or being able to edit these? This is accommodated, too.
It's up to you to set up your Mailchimp Interest Groupings so that this fieldset
is hidden from subscribers, but then you can just link a CiviCRM group to
one of those. These groups will never update from Mailchimp to CiviCRM.

Nb. Mailchimp sometimes calls Interest Groupings just "Groups", which gets
very confusing because you have Groups of Groups, and of course CiviCRM uses
the word Group, too! Here I will stick to calling the Mailchimp fields
"Interest Groupings" which each contain a number of "Mailchimp Groups", to
differentiate them from CiviCRM groups.

## How to Install

1. Download extension from https://github.com/veda-consulting/uk.co.vedaconsulting.mailchimp/releases/latest.
2. Unzip / untar the package and place it in your configured extensions directory.
3. When you reload the Manage Extensions page the new “Mailchimp” extension should be listed with an Install link.
4. Proceed with install.

Before the extension can be used you must set up your API keys...

**veda todo**: how do people set up their API keys etc.

## Basic Use

In Mailchimp: Set up an empty list, lets call it Newsletter.

In CiviCRM: you need a group to track subscribers to your Mailchimp Newsletter
List. You can create a new blank group, or choose an existing group (or smart
group). The CiviCRM Group's settings page has an additional fieldset called
Mailchimp.

Choose the integration option, called "Sync membership of this group with membership of a Mailchimp List" then choose your list name.
![Screenshot of integration options](images/group-config-form-1.png)

