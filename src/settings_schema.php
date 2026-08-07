<?php
declare(strict_types=1);

/**
 * What the instance settings *are*, described rather than drawn.
 *
 * The web interface has its own form for these - six hundred lines of it - and a
 * native client cannot use any of that. The obvious answer was to write the same
 * form again in Swift, which is thirty fields spelled out a second time, wrong
 * the first day somebody adds a thirty-first.
 *
 * So this file says what each setting is - a name, a kind, its choices, what it
 * means - and both the API and anything reading the API work from that. Adding a
 * setting here puts it in the app; nothing else has to know.
 *
 * Deliberately not the storage. Settings live in the `settings` table and are read
 * with setting() and written with set_setting(), which logs the change. This is a
 * description of them, and it holds no values.
 */

/**
 * Every setting the API will hand out or accept, in sections.
 *
 * Kinds:
 *   text      a line of text
 *   url       the same, checked as an address
 *   int       a whole number, with min and max
 *   bool      "1" or "" in the table, true or false over the wire
 *   select    one of `options`, which are value => label
 *   secret    a password. Never sent back; sending one replaces it, and sending
 *             null clears it.
 *
 * @return array<string, array{label: string, help?: string, fields: array<string, array>}>
 */
function settings_schema(): array
{
    return [
        'general' => [
            'label'  => 'General',
            'fields' => [
                'instance_name' => [
                    'kind'    => 'text',
                    'label'   => 'Instance name',
                    'help'    => 'What this instance calls itself, in the bar and in mail it sends.',
                    'default' => 'RetroHive',
                    'max'     => 120,
                ],
                'site_url' => [
                    'kind'  => 'url',
                    'label' => 'Public address',
                    'help'  => 'Used to build links in mail and notifications. Without it they point nowhere.',
                    'max'   => 255,
                ],
                'search_indexing' => [
                    'kind'    => 'select',
                    'label'   => 'Search engines',
                    'default' => 'discourage',
                    'options' => [
                        'allow'      => 'May index this instance',
                        'discourage' => 'Asked not to',
                    ],
                ],
                'libraries_deletable' => [
                    'kind'    => 'bool',
                    'label'   => 'Libraries may be deleted',
                    'help'    => 'Off means a library can only be emptied, not removed.',
                    'default' => '',
                ],
                'structure_source' => [
                    'kind'    => 'url',
                    'label'   => 'Structure source',
                    'help'    => 'Where companies, categories and models are fetched from when structure data is refreshed.',
                    'default' => 'https://raw.githubusercontent.com/norrorthoarders/retrohive/main/structure',
                    'max'     => 255,
                ],
                'avatar_approval' => [
                    'kind'    => 'bool',
                    'label'   => 'An administrator approves a new profile picture',
                    'help'    => 'Off, a picture appears the moment somebody uploads it. On, it waits '
                               . 'in Instance Users until an administrator says yes, and the picture '
                               . 'already there stays up meanwhile - what is pending is the change, '
                               . 'not the removal. Administrators are exempt from their own switch.',
                    'default' => '',
                ],
            ],
        ],

        'signin' => [
            'label' => 'Sign-in',
            'help'  => 'Security rules that apply once an account already exists, regardless of how it was made.',
            'fields' => [
                'require_email_verification' => [
                    'kind'    => 'bool',
                    'label'   => 'Require a confirmed email address to sign in',
                    'help'    => 'Needs a relay that has answered a test message, or this locks out everybody, including whoever ticks it.',
                    'default' => '',
                ],
            ],
        ],

        'mail' => [
            'label' => 'Mail',
            'help'  => 'Without this, notifications that go by mail are simply not sent.',
            'fields' => [
                'smtp_enabled' => [
                    'kind'    => 'bool',
                    'label'   => 'Send mail',
                    'default' => '',
                ],
                'smtp_host' => ['kind' => 'text', 'label' => 'Host', 'max' => 255],
                'smtp_port' => [
                    'kind'    => 'int',
                    'label'   => 'Port',
                    'default' => '587',
                    'min'     => 1,
                    'max'     => 65535,
                ],
                'smtp_security' => [
                    'kind'    => 'select',
                    'label'   => 'Security',
                    'default' => 'starttls',
                    'options' => [
                        ''         => 'None',
                        'starttls' => 'STARTTLS',
                        'tls'      => 'TLS',
                    ],
                ],
                'smtp_auth'     => ['kind' => 'bool',   'label' => 'Sign in to the server', 'default' => '1'],
                'smtp_username' => ['kind' => 'text',   'label' => 'Username', 'max' => 190],
                'smtp_password' => ['kind' => 'secret', 'label' => 'Password'],
                'smtp_from'     => [
                    'kind'  => 'text',
                    'label' => 'From address',
                    'help'  => 'Many servers refuse mail whose sender is not an address they host.',
                    'max'   => 190,
                ],
                'smtp_from_name' => ['kind' => 'text', 'label' => 'From name', 'max' => 120],
            ],
        ],

        'catalogue' => [
            'label' => 'Catalogue',
            'help'  => 'How entries look when nobody has told the catalogue what they look like.',
            'fields' => [
                'stock_images' => [
                    'kind'    => 'bool',
                    'label'   => 'Generic pictures for entries with no photograph',
                    'help'    => 'A blank big box, jewel case, VHS slip or record sleeve, chosen from '
                               . 'what the entry says it is and what it comes on. Never shown ahead of '
                               . 'a real photograph, or of a picture set on the entry\'s own branch. '
                               . 'Off means those entries show nothing at all, which is the honest '
                               . 'alternative: a generic picture describes a format, not the object.',
                    'default' => '1',
                ],
            ],
        ],

        'logging' => [
            'label' => 'Logging',
            'help'  => 'The log is always written to the database. These decide what else happens to it.',
            'fields' => [
                'log_min_severity' => [
                    'kind'    => 'select',
                    'label'   => 'Record down to',
                    'help'    => 'Anything less severe than this is discarded.',
                    'default' => '6',
                    'options' => [
                        '3' => 'Errors',
                        '4' => 'Warnings',
                        '5' => 'Notices',
                        '6' => 'Information',
                        '7' => 'Debug',
                    ],
                ],
                'log_retention_days' => [
                    'kind'    => 'int',
                    'label'   => 'Keep for (days)',
                    'help'    => 'Zero keeps everything, for ever.',
                    'default' => '90',
                    'min'     => 0,
                    'max'     => 3650,
                ],
                'logfile_enabled' => ['kind' => 'bool', 'label' => 'Also write a file', 'default' => ''],
                'logfile_path'    => ['kind' => 'text', 'label' => 'File', 'max' => 255],
                'syslog_enabled'  => ['kind' => 'bool', 'label' => 'Also send to syslog', 'default' => ''],
                'syslog_host'     => ['kind' => 'text', 'label' => 'Syslog host', 'max' => 190],
                'syslog_port'     => [
                    'kind'    => 'int',
                    'label'   => 'Syslog port',
                    'default' => '514',
                    'min'     => 1,
                    'max'     => 65535,
                ],
                'syslog_protocol' => [
                    'kind'    => 'select',
                    'label'   => 'Protocol',
                    'default' => 'udp',
                    'options' => ['udp' => 'UDP', 'tcp' => 'TCP'],
                ],
                'syslog_facility_security' => [
                    'kind'    => 'select',
                    'label'   => 'Facility for the security stream',
                    'default' => '10',
                    'options' => syslog_facility_options(),
                ],
                'syslog_facility_server' => [
                    'kind'    => 'select',
                    'label'   => 'Facility for the server stream',
                    'default' => '16',
                    'options' => syslog_facility_options(),
                ],
            ],
        ],
    ];
}

/**
 * The facilities worth offering.
 *
 * Numbers, because that is what goes on the wire and what the log code already
 * stores. The names are the conventional ones so somebody reading a syslog
 * configuration recognises them.
 *
 * @return array<string, string>
 */
function syslog_facility_options(): array
{
    return [
        '1'  => 'user',
        '4'  => 'auth',
        '10' => 'authpriv',
        '16' => 'local0',
        '17' => 'local1',
        '18' => 'local2',
        '19' => 'local3',
        '20' => 'local4',
        '21' => 'local5',
        '22' => 'local6',
        '23' => 'local7',
    ];
}

/** Flat: every field in the schema, by name. */
function settings_schema_fields(): array
{
    $out = [];
    foreach (settings_schema() as $section) {
        foreach ($section['fields'] as $name => $field) {
            $out[$name] = $field;
        }
    }
    return $out;
}

/**
 * One value, as it should go over the wire.
 *
 * A secret is never sent - only whether one is set. Sending a masked string that
 * looks like a password invites a client to send it back, which would store the
 * mask as the password.
 */
function setting_to_api(string $name, array $field)
{
    $raw = setting($name, $field['default'] ?? null);

    switch ($field['kind']) {
        case 'bool':   return $raw !== null && $raw !== '' && $raw !== '0';
        case 'int':    return $raw === null ? null : (int) $raw;
        case 'secret': return null;
        default:       return $raw;
    }
}

/**
 * Check one incoming value, and hand back what to store.
 *
 * Returns [$storable, $error]. Refusing is the point: these are the switches that
 * can stop mail, stop logging, or open registration to the world, and "the client
 * should have checked" is not a check.
 */
function setting_from_api(string $name, array $field, $value): array
{
    switch ($field['kind']) {
        case 'bool':
            if (!is_bool($value) && !in_array($value, ['1', '0', '', 1, 0], true)) {
                return [null, $name . ' takes true or false.'];
            }
            return [(is_bool($value) ? $value : (string) $value === '1') ? '1' : '', null];

        case 'int':
            if (!is_int($value) && !(is_string($value) && preg_match('/^-?\d+$/', $value))) {
                return [null, $name . ' takes a whole number.'];
            }
            $n = (int) $value;
            if (isset($field['min']) && $n < $field['min']) {
                return [null, $name . ' cannot be below ' . $field['min'] . '.'];
            }
            if (isset($field['max']) && $n > $field['max']) {
                return [null, $name . ' cannot be above ' . $field['max'] . '.'];
            }
            return [(string) $n, null];

        case 'select':
            $value = (string) $value;
            if (!array_key_exists($value, $field['options'])) {
                return [null, $name . ' must be one of: '
                             . implode(', ', array_keys($field['options'])) . '.'];
            }
            return [$value, null];

        case 'secret':
            // null clears it; anything else replaces it. There is no way to ask
            // what it currently is, which is the whole point of the kind.
            if ($value === null) { return ['', null]; }
            if (!is_string($value)) { return [null, $name . ' takes text.']; }
            return [$value, null];

        case 'url':
            $value = trim((string) $value);
            if ($value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
                return [null, $name . ' does not look like an address.'];
            }
            return [mb_substr($value, 0, $field['max'] ?? 255), null];

        default:
            if (!is_string($value) && !is_int($value)) {
                return [null, $name . ' takes text.'];
            }
            return [mb_substr(trim((string) $value), 0, $field['max'] ?? 255), null];
    }
}
