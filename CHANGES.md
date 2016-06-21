# Version 2.0

Massive changes to accomodate Mailchimp Api3 which is completely different, and
automated testing capability.

An upgrade hook is added to migrate from versions using Api <3. This must be run
while Api2 is still working, i.e in 2016 according to Mailchimp.

These changes have been made by Rich Lott / artfulrobot.uk with thanks to the
*Sumatran Organutan Society* for funding a significant chunk of the work.

Added this markdown changelog :-)

## Contact and email selection

Contacts must now

- have an email available
- not be deceased (new)
- not have `is_opt_out` set
- not have `do_not_email` set

The system will prefer the bulk email address instead of the primary one.
If no bulk one is available, then it will pick the primary, or if that's not
there either (?!) it will pick any.

## CiviCRM post hook changes

The *post hook* used to fire API calls for all GroupContact changes. I've changed
this to only do so when there is only one contact affected. This hook could be
called with 1000 contacts which would have fired 1000 API calls one after
another, so for stability I removed that 'feature' and for clarity I chose 1 as
the maximum number of contacts allowed.


## Identifying contacts in CiviCRM from Mailchimp

Most of the fields in the tmp tables are now *`NOT NULL`*. Having nulls just made
things more complex and we don't need to distinguish different types of
not-there data.

A new method is added to identify the CiviCRM contact Ids from Mailchimp details
that looks to the subscribers we're expecting to find. This solves the issue
when two contacts (e.g. related) are in CiviCRM with the same email, but only
one of them is subscribed to the list - now it will pick the subscriber. This
test ought to be the fastest of the methods, so it is run first.

The email-is-unique test to identify a contact has been modified such that if
the email is unique to a particular contact, we guess that contact. Previously
the email had to be unique in the email table, which excludes the case that
someone has the same email in several times (e.g. once as a billing, once as a
bulk...).

The email and name SQL for 'guessing' the contact was found buggy by testing so
has been rewritten - see tests.


## Group settings page

Re-worded integration options for clarity. Added fixup checkbox, default ticked.
On saving the form, if this is ticked, CiviCRM will ensure the webhook settings
are correct at Mailchimp.

## Mailchimp Settings Page

Checks all lists including a full check on the webhook config.

## Changes of email from Mailchimp's 'upemail' webhook 

Previously we found the first email match from the email table and changed that.
This does not allow for the same email being in the table multiple times.

This *should* only happen to people we know are subscribed to the list. Also,
there's the case that a user has a primary email of personal@example.com and
wanted to change it at Mailchimp to mybulkmail@example.com.

So now what we do is:

1. find the email. Filter for contacts we know to be subscribed.

2. if this *is* their bulk email, just change it.

3. if it's *not* their bulk email, do they have a bulk email?

   Yes: change that.
   No:  create that with the new email.

Ideally we'd have staff notified to check the emails, possibly in the 3:No case,
set the email to on hold. But without further human interaction it's safest to
do as outlined above.

The upemail will change *all* emails found, not just the first, so long as they
belong to a single contact on the list. So if the email is in CiviCRM against a
different contact who is not in the mailchimp list, that will be left unchanged.

## Changes to response to Mailchimp's 'cleaned' webhook

Previously the first matching was found and put on hold.

Cleaned comes in two flavours: hard (email keeps bouncing) and abuse (they don't
like you anymore).

If the email is bouncing for mailchimp, in all their deliverability might, it's
almost definitely going to bounce for us. So in this case we put all matching
emails on hold.

In the case of 'abuse' we limit the action to email(s) belonging to contacts on
this list only, since it might be to do with that list.




