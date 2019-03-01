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

];
