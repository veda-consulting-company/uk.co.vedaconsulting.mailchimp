uk.co.vedaconsulting.mailchimp
==============================

## Introduction

This extension helps you keep a CiviCRM group in sync with subscribers of a
Mailchimp list. It can sync different CiviCRM groups to different Mailchimp lists.

Additionally, if you use Mailchimp's *Interests* feature, you can map particular
Mailchimp interests to a CiviCRM group. You can choose whether this is a group
that the subscriber can edit (using Mailchimp's forms), or not.

Some updates happen in near-real time. So once set-up, if someone new subscribes
using a Mailchimp embedded sign-up form, they will be added to CiviCRM (if not
already found) and joined to the appropriate group. Likewise, if you use the
"Add to Group" widget on a contact's Groups tab to add someone to a group that
is sync-ed with a Mailchimp List, they will be immediately added. Likewise with
individual unsubscribes/removals.

However not all updates are possible (or desireable) this way, and to cope with
these there's two mechanisms offered:

1. **Pull Sync: updates CiviCRM from Mailchimp**, assuming Mailchimp is correct.
   You'd do this if you had just made a bulk change at Mailchimp, e.g. you'd
   just imported a new list of contacts to a list and you wanted to make sure
   that these contacts were in CiviCRM.

2. **Push Sync: updates Mailchimp from CiviCRM**, assuming CiviCRM is correct.
   You'd do this if you'd just made a bulk change at CiviCRM, e.g. added/removed
   a load of contacts to one or more sync-ed groups, or changed records such
   that now they qualify (or cease to qualify) for membership of a Smart Group.

Typically day-to-day changes made at Mailchimp (poeple clicking unsubscribe, or
individuals subscribing or updating their preferences) are all done right away,
so except for bulk changes that you do to your list deliberately, you usually do
not need to use the **Pull**.

You can set up the **Push** to run at scheduled intervals, if you find that's
useful, otherwise do it after a change, or at least before you send out an email.

**Note: syncing works best when done regularly**. If changes are made at both
ends there's no way to figure out which way is correct and you'll be forced to
choose: pull or push? So it's important you have an awareness of this in your
day-to-day workflows.

## Take care. This can make large-scale bulk updates to your data.

Until you're confident in the way this works and your own workflows, **make sure
to backup both your mailchimp and civicrm contacts**. Regular periodic backups
are also sensible practise.

## How to Install

1. Download extension from https://github.com/veda-consulting/uk.co.vedaconsulting.mailchimp/releases/latest.
2. Unzip / untar the package and place it in your configured extensions directory.
3. When you reload the Manage Extensions page the new “Mailchimp” extension should be listed with an Install link.
4. Proceed with install.

Before the extension can be used you must set up your API keys. To get your
Mailchimp account's API you should follow [Mailchimp's
instructions](http://kb.mailchimp.com/accounts/management/about-api-keys).

Once you’ve setup your Mailchimp API key it can be added to CiviCRM through
"Mailings >> Mailchimp Settings" screen, with url
`https://<<your_site>>/civicrm/mailchimp/settings?reset=1`. Using “Save & Test”
button will test that a connection can be made to your Mailchimp account, and if
your API settings are correct.

## Basic Use Example

In Mailchimp: Set up an empty list, lets call it Newsletter.

In CiviCRM: you need a group to track subscribers to your Mailchimp Newsletter
List. You can create a new blank group, or choose an existing group (or smart
group). The CiviCRM Group's settings page has an additional fieldset called
Mailchimp.

Choose the integration option, called "*Membership Sync: Contacts in this group
should be subscribed to a Mailchimp List*" then choose your list name.

Ensure the tickbox is ticked that says "*Ensure lists's webhook settings are
correct*".

Save your group's settings.

The next step is to get CiviCRM and Mailchimp in sync. **Which way you do this
is important**. In our example we have assumed a new, blank Mailchimp list and a
populated CiviCRM Group. So we want to do a **Push CiviCRM to Mailchimp** Sync.
However, if we had set up an empty group in CiviCRM for a pre-existing Mailchimp
list, we would want to do a **Pull Mailchimp to CiviCRM** sync. If you get it
wrong you'll end up removing/unsubscribing everyone!

So for our example, with an empty Mailchimp list and a CiviCRM newsletter group
with contacts in, you'll find the **CiviCRM to Mailchimp Sync** function in the
**Mailings** menu.

You'll notice a tick-box for **Dry-Run**. If this is ticked then all the work is
done except for the actual updates. Use this if you're at all unclear on what's
about to happen and check the results make sense.

Push the Sync button and after a while (for a large list/group) you should see a
summary screen.


### From here on...

Any un/subscribes from the Mailchimp end will be handled (almost) instantly
using the webhook. Changes at the CiviCRM end will also be handled (almost) instantly using CiviCRM database post hook.

### Important note about unsubscribed contacts

Note: If anybody is added/removed to group in civiCRM, they get subscribed/unsubscribed to Mailchimp immediately.
If Webhook is set properly in Mailchimp, then subscribe/unsubscribe in Mailchimp is treated back to civiCRM
immediately as addition/deletion to group


We have an upcoming feature that will give you the option to force
a CiviCRM to Mailchimp sync which will automatically do the necessary deletions,
but this is not included in the current version. Watch this space.

## Interests Example

For this example we'll set up two interest groupings in Mailchimp, one called
*Things I like* that is publically viewable, and one called *Private* that is hidden from
subscribers. Within "Things I like" add Mailchimp Groups such as "bananas",
"organic farming", "climate change activism". Within the "Private" Mailchimp
Interest Grouping, you might add Mailchimp Groups called "major donor", "VIPs"
etc.

Please **take care** and follow Mailchimp's help pages for how to restrict the
visibility of the Private interest grouping.

Now back in CiviCRM, setup groups to map to these Mailchimp Groups. When you
look at the CiviCRM group's settings page, choose "*Interest Sync: Contacts in
this group should have an "interest" set at Mailchimp*".

Here you can see the two options about whether Mailchimp subscribers are supposed
to be able to edit their membership of this interest grouping.

So for the Private interest grouping, choose the first, No option, for the
public "Things I like" one, choose the second option.

**Please note** that while it's possible to configure one Mailchimp Group to be
updatable and another to be non-updatable within the same mailchimp interest
grouping, this will lead to unpredictable results. Stick to the rule: if it's
public, it should be updateable, if it's hidden/private, it should be not
updatable.

When you run the sync, these grouping will be updated accordingly. So again,
when you first set it up, which source has the data: Mailchimp or CiviCRM?
Choose Pull or Push accordingly.



## How contacts are matched and about "Titanics" - un-sync-able contacts.

The extension tries hard to match contact details from Mailchimp with existing
contacts in CiviCRM. This is explained in the [README-tech](README-tech.md) file
and documented in more detail in the code comments including tests.

The basics are:

1. Emails are the primary thing to match (obviously!). While CiviCRM will always
   choose a "bulk mail" email address for giving to Mailchimp, it will check
   every email, bulk mail or other when trying to find a contact.

2. The default Mailchimp `FNAME` and `LNAME` "*merge fields*" are assumed and
   are an important part of a successful sync workflow. There is the option to
   use a `NAME` merge field at Mailchimp, but this is far less helpful in many
   ways, so avoid this if you can.

3. Precidence will be given to contacts that are in the sync-ed membership
   group. So if the same email and name belongs to two contacts but one is in
   the membership group, it will assume that's the one to work with.

4. If there are multiple contacts with the same name and email, and none are in
   the group, a random one will be picked. Then after this will be preferred
   (see point 3).

5. "*Titanics*" There are still cases of messy data in CiviCRM that cannot be
   resolved. e.g. 2 contacts have the same email, neither is in the group, and
   neither has a name that matches the incoming data from Mailchimp. These are
   considered un-sync-able. It would be wrong to create another contact
   (possibiliy of adding to the duplicates) and we can't choose between them.
   Such contacts are excluded from the Sync and will remain as they are on
   Mailchimp, until such time that the duplication or incorrect names in CiviCRM
   is sorted out. These contacts are listed on the summary page after a sync
   operation.

## 'Cleaned' emails

When Mailchimp determines an email must be 'cleaned' CiviCRM will put that email
"on hold". Cleaned in Mailchimp parlance means the email is duff, e.g. hard
bounces.

## Difficult Mailchimp policies

Mailchimp have certain policies in place to protect their own interests and
sender reputation. These can cause problems with sync.

For instance if someone unsubscribes from a list using the link at the bottom of
their email, **you are not allowed to re-subscribe them**. Previously there have
been work-arounds to this (e.g. delete the member and re-add them) and while
there are still loopholes in Mailchimp's API that could be exploited to this
end, as it is against their policy we should not design a system that makes use
of these since the loopholes (bugs) could be fixed at any time, breaking our
system without notice.

Note that this rule does not apply if it was us (CiviCRM) who unsubscribed the
member.

Likewise you cannot (or are not *supposed* to be able to) re-subscribe a
'cleaned' email address.

Mailchimp's policy is that these emails can only be updated to "pending" by the
API, which means Mailchimp sends a "do you want to subscribe to..." email. The
extension does not currently handle this case.

## Thanks and Authors.

Originally work was done by the science gallery, then Veda Consulting and
Artful Robot. Thanks also to Sumatran Orangutan Society for funding some of the work to
implement Mailchimp API v3.

## Technical people and developers

Please see <README-tech.md>
