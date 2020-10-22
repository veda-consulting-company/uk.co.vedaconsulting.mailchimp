<?php
return [
  'mailchimp_security_key' => [
    'name'        => 'mailchimp_security_key',
    'title'       => ts('Mailchimp webhook security key'),
    'description' => ts('Security key used by Mailchimp Webhook requests to identify themselves.'),
    'group_name'  => 'domain',
    'type'        => 'String',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],

  'mailchimp_api_key' => [
    'name'        => 'mailchimp_api_key',
    'title'       => ts('Mailchimp API key'),
    'description' => ts('Private API key used to access the Mailchimp API.'),
    'group_name'  => 'domain',
    'type'        => 'String',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],

  'mailchimp_enable_debugging' => [
    'name'        => 'mailchimp_enable_debugging',
    'title'       => ts('Mailchimp debugging'),
    'description' => ts('If enabled lots of debugging information is created relating to the Mailchimp sync extension.'),
    'group_name'  => 'domain',
    'type'        => 'Boolean',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'mailchimp_sync_checksum' => [
    'name'        => 'mailchimp_sync_checksum',
    'title'       => ts('Sync Checksum'),
    'description' => ts('If enabled will push CiviCRM Contact checksum, which then may be used in Mailchimp tokens.'),
    'group_name'  => 'domain',
    'type'        => 'Boolean',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'mailchimp_sync_tags' => [
    'name'        => 'mailchimp_sync_tags',
    'title'       => ts('Sync Checksum'),
    'description' => ts('If enabled will push CiviCRM Contact tags, which then may be used for Mailchimp segmentation.'),
    'group_name'  => 'domain',
    'type'        => 'Boolean',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],
  'mailchimp_sync_profile' => [
    'name'        => 'mailchimp_sync_profile',
    'title'       => ts('Profile'),
    'description' => ts('Optionally select a profile to include in contact syncronization.
              These may be useful for segmentation, etc. on mailchimp. Synchronizing custom fields will slow
                        down the process so only do so if necessary and use a profile with only the fields you need.'),
    'group_name'  => 'domain',
    'type'        => 'String',
    'default'     => FALSE,
    'add'         => '5.10',
    'is_domain'   => 1,
    'is_contact'  => 0,
  ],

];
