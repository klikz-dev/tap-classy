<?php

use GuzzleHttp\Client;
use SingerPhp\SingerTap;
use SingerPhp\Singer;

class ClassyTap extends SingerTap
{
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * The OAuth URL of the Classy API
     * @var string
     */
    const BASE_AUTH_URL = 'https://api.classy.org/oauth2/auth';

    /**
     * The base URL of the Classy API
     * @var string
     */
    const BASE_API_URL = 'https://api.classy.org/2.0';

    /**
     * Maximum number of API retries
     * @var integer
     */
    const RETRY_LIMIT = 5;

    /**
     * Delay of retry cycle (seconds)
     * @var integer
     */
    const RETRY_DELAY = 30;

    /**
     * Records per page
     * @var integer
     */
    const RECORDS_PER_PAGE = 100; // default: 20

    /**
     * Classy Organization ID
     * @var string
     */
    private $org_id = '';

    /**
     * Classy API Client ID
     * @var string
     */
    private $client_id = '';

    /**
     * Classy API Client Secret
     * @var string
     */
    private $client_secret = '';

    /**
     * Classy API Access Token
     * @var string
     */
    private $access_token = '';

    /**
     * Singer PHP Types Array
     * @var array
     */
    private $types = [
        'string'    => Singer::TYPE_STRING,
        'integer'   => Singer::TYPE_INTEGER,
        'float'     => Singer::TYPE_FLOAT,
        'boolean'   => Singer::TYPE_BOOLEAN,
        'object'    => Singer::TYPE_OBJECT,
        'array'     => Singer::TYPE_ARRAY,
        'datetime'  => Singer::TYPE_DATETIME
    ];

    /**
     * Tests if the connector is working then writes the results to STDOUT
     */
    public function test()
    {
        $this->client_id     = $this->singer->config->input('client_id');
        $this->client_secret = $this->singer->config->input('client_secret');

        try {
            $this->generateAccessToken();
            $this->singer->writeTestResult(true);
        } catch (Exception $e) {
            $this->singer->writeTestResult(message: $e->getMessage(), thrown: $e);
        }
    }

    /**
     * Gets all schemas/tables and writes the results to STDOUT
     */
    public function discover()
    {
        $this->singer->logger->debug('Starting discover for tap Classy');

        $this->client_id     = $this->singer->config->setting('client_id');
        $this->client_secret = $this->singer->config->setting('client_secret');

        $this->generateAccessToken();

        foreach ($this->singer->config->catalog->streams as $stream) {
            $table = $stream->stream;

            $this->singer->logger->debug("Writing schema for {$table}");

            $columns = [];
            $column_map = $this->table_map[$table]['columns'];
            foreach ($column_map as $colName => $colType) {
                $columns[$colName] = [
                    'type' => $this->types[$colType] ?? Singer::TYPE_STRING
                ];
            }

            $indexes = $this->table_map[$table]['indexes'];
            $this->singer->writeMeta(['unique_keys' => $indexes]);

            $this->singer->writeSchema(
                stream: $table,
                schema: $columns,
                key_properties: $indexes
            );
        }
    }

    /**
     * Gets the record data and writes to STDOUT
     */
    public function tap()
    {
        $this->singer->logger->debug('Starting sync for tap Classy');
        $this->singer->logger->debug("catalog", [$this->singer->config->catalog]);

        $this->org_id        = $this->singer->config->setting('org_id');
        $this->client_id     = $this->singer->config->setting('client_id');
        $this->client_secret = $this->singer->config->setting('client_secret');

        foreach ($this->singer->config->catalog->streams as $stream) {
            $table = $stream->stream;

            // Full Replace
            $this->singer->logger->debug("Writing schema for {$table}");

            $columns = [];
            $column_map = $this->table_map[$table]['columns'];
            foreach ($column_map as $colName => $colType) {
                $columns[$colName] = [
                    'type' => $this->types[$colType] ?? Singer::TYPE_STRING
                ];
            }

            $indexes = $this->table_map[$table]['indexes'];

            $this->singer->writeSchema(
                stream: $table,
                schema: $columns,
                key_properties: $indexes
            );
            ////

            $this->singer->logger->debug("Starting sync for {$table}");

            $records = $this->fetchTableData($table);

            $total_records = 0;
            foreach ($records as $record) {
                $record = $this->formatRecord($record, $columns);

                $this->singer->writeRecord(
                    stream: $table,
                    record: $record
                );

                $total_records++;
            }

            $this->singer->writeMetric(
                'counter',
                'record_count',
                $total_records,
                [
                    'table' => $table
                ]
            );

            $this->singer->logger->debug("Finished sync for {$table}");
        }
    }

    /**
     * Writes a metadata response with the tables to STDOUT
     */
    public function getTables()
    {
        $tables = array_values(array_keys($this->table_map));
        $this->singer->writeMeta(compact('tables'));
    }

    /**
     * Get a access token
     */
    public function generateAccessToken()
    {
        $client = new Client();
        $response = $client->post(
            self::BASE_AUTH_URL, 
            [
                'form_params' => [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->client_id,
                    'client_secret' => $this->client_secret
                ],
                'http_errors' => false
            ]
        );

        $status_code = $response->getStatusCode();
        switch ($status_code) {
            case 200:
                $token = json_decode((string) $response->getBody(), true);
                $this->access_token = $token['access_token'];
                break;
            case 400:
                throw new Exception("The provided client credentials are invalid.");
            default:
                throw new Exception("An error occurred trying to get an access token. Expected 200 but received '{$status_code}'");
        }
    }

    /**
     * Fetch table data
     * @param  string $table  The table name
     * @return array
     */
    public function fetchTableData($table)
    {
        $endpoint = str_replace('_', '-', $table);

        $ids     = [];
        $records = [];

        $page = 1;
        do {
            $response = $this->requestWithRetries("/organizations/{$this->org_id}/{$endpoint}", [
                'page'     => $page,
                'per_page' => self::RECORDS_PER_PAGE
            ]);

            if ( isset($response['data']) ) {
                foreach ($response['data'] as $record) {
                    if ( isset($record['id']) && ! in_array($record['id'], $ids) ) {
                        $ids[]     = $record['id'];
                        $records[] = $record;
                    }
                }
            }

            $page++;
            $last_page = $response['last_page'] ?? 1;
        } while ($page <= $last_page);

        return $records;
    }

    /**
     * Format records to match table columns
     * @param array   $record           The response array
     * @param array   $columns          The record model
     * @return array
     */
    public function formatRecord($record, $columns) {
        // Remove unmapped fields from the response.
        $record = array_filter($record, function($key) use($columns) {
            return array_key_exists($key, $columns);
        }, ARRAY_FILTER_USE_KEY);

        // column mapping for missing response fields.
        foreach ($columns as $colKey => $colVal) {
            if (!array_key_exists($colKey, $record)) {
                $record[$colKey] = null;
            }
        }

        return $record;
    }

    /**
     * Make a request with retry logic
     * @param string    $uri        The API URI
     * @param  array    $params     The array of API query params
     * @return array    The API response array
     */
    public function requestWithRetries($uri, $params = [])
    {
        $attempts = 1;
        while (true) {
            try {
                return $this->request($uri, $params);
            } catch (Exception $e) {
                if ($attempts > self::RETRY_LIMIT) {
                    throw $e;
                }
                $this->singer->logger->debug("Classy API request failed. Retrying. Attempt {$attempts} of " . self::RETRY_LIMIT . " in " . self::RETRY_DELAY . " seconds.");
                $attempts++;
                sleep(self::RETRY_DELAY);
            }
        }
    }

    /**
     * Make a request to the Classy API
     * @param  string   $uri        The API URI
     * @param  array    $params     The array of API query params
     * @return array    The API response array
     */
    public function request($uri, $params = [])
    {
        $client = new Client();
        $response = $client->get(
            self::BASE_API_URL . $uri, 
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->access_token,
                ],
                'query' => $params,
                'http_errors' => false
            ]
        );

        $status_code = $response->getStatusCode();
        switch ($status_code) {
            case 200:
                return (array) json_decode($response->getBody()->getContents(), true);
            case 401:
                $this->singer->logger->debug("The access token is invalid or expired. Regenerating.");
                $this->generateAccessToken();
                return $this->request($uri, $params);
            case 401:
                throw new Exception("API Key doesn't have permission to the requested resource. uri: {$uri}");
            case 404:
                throw new Exception("API endpoint not found. uri: {$uri}");
            case 429:
                $retryAfter = $response->hasHeader('Retry-After') ? $response->getHeaderLine('Retry-After') : self::RETRY_DELAY;
                $this->singer->logger->debug("Too many requests. Retry in {$retryAfter} seconds.");
                sleep($retryAfter);
                return $this->request($uri, $params);
            default:
                throw new Exception("Server side error occurred. uri: {$uri}, code: {$status_code}");
        }
    }

    /**
     * Array of table data.
     * @var array
     */
    private $table_map = [
        'activity' => [
            'columns' => [
                'fundraising_team_id' => 'integer',
                'link_id' => 'integer',
                'link_text' => 'string',
                'id' => 'integer',
                'type' => 'string',
                'member_id' => 'integer',
                'organization_id' => 'integer',
                'campaign_id' => 'integer',
                'fundraising_page_id' => 'integer',
                'created_at' => 'datetime',
                'transaction_id' => 'integer',
                'organization' => 'object',
                'member' => 'object',
                'fundraising_team' => 'object',
                'transaction' => 'object',
                'campaign' => 'object',
                'comments' => 'array',
                'business' => 'object',
                'designation' => 'object'
            ],
            'indexes' => [
                'id',
                'campaign_id',
                'transaction_id'
            ]
        ],
        'campaigns' => [
            'columns' => [
                'internal_name' => 'string',
                'default_team_appeal_email' => 'string',
                'default_team_thank_you_email' => 'string',
                'status' => 'string',
                'channel_id' => 'integer',
                'category_id' => 'integer',
                'channel_keywords' => 'string',
                'timezone_identifier' => 'string',
                'created_with' => 'string',
                'contact_phone' => 'string',
                'default_page_goal' => 'integer',
                'default_team_goal' => 'integer',
                'location_details' => 'string',
                'raw_currency_code' => 'string',
                'raw_goal' => 'string',
                'sort_designation_by' => 'string',
                'display_group_name' => 'boolean',
                'campaign_template_id' => 'integer',
                'id' => 'integer',
                'name' => 'string',
                'started_at' => 'datetime',
                'ended_at' => 'datetime',
                'default_page_appeal_email' => 'string',
                'default_page_thank_you_email' => 'string',
                'venue' => 'string',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
                'postal_code' => 'string',
                'address1' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'hide_from_profile' => 'boolean',
                'goal' => 'float',
                'external_url' => 'string',
                'contact_email' => 'string',
                'designation_id' => 'integer',
                'type' => 'string',
                'is_general' => 'integer',
                'organization_id' => 'integer',
                'is_fees_free' => 'boolean',
                'canonical_url' => 'string',
                'currency_code' => 'string',
                'effective_fixed_fot_percent' => 'float',
                'effective_flex_rate_percent' => 'float',
                'logo_id' => 'integer',
                'logo_url' => 'string',
                'team_cover_photo_id' => 'integer',
                'team_cover_photo_url' => 'string',
                'add_registration_fee' => 'boolean',
                'allow_duplicate_fundraisers' => 'boolean',
                'allow_fundraising_pages' => 'boolean',
                'allow_team_fundraising' => 'boolean',
                'crypto_giving' => 'string',
                'default_page_appeal' => 'string',
                'default_page_post_asset_id' => 'integer',
                'default_page_post_body' => 'string',
                'default_page_post_title' => 'string',
                'default_team_appeal' => 'string',
                'default_team_post_asset_id' => 'integer',
                'default_team_post_body' => 'string',
                'default_team_post_title' => 'string',
                'default_thank_you_text' => 'string',
                'embedded_giving' => 'string',
                'exit_modal' => 'boolean',
                'fixed_fot_percent' => 'float',
                'flex_rate_percent' => 'float',
                'is_billing_address_required' => 'boolean',
                'is_ended_at_hidden' => 'boolean',
                'is_started_at_hidden' => 'boolean',
                'pages_can_set_appeal' => 'boolean',
                'pages_can_set_goal' => 'boolean',
                'team_membership_policy' => 'string',
                'teams_can_set_appeal' => 'boolean',
                'teams_can_set_goal' => 'boolean',
                'ticket_pass_on_fees' => 'boolean',
                'whitelist_url' => 'string',
                'allow_ecards' => 'boolean',
                'classy_mode_appeal' => 'string',
                'classy_mode_checked_by_default' => 'boolean',
                'classy_mode_enabled' => 'boolean',
                'corporate_donation_enabled' => 'boolean',
                'custom_url' => 'string',
                'dcf_allowed' => 'boolean',
                'dcf_enabled' => 'boolean',
                'disable_donation_attribution' => 'boolean',
                'hide_anonymous_donations' => 'boolean',
                'hide_contact_opt_in' => 'boolean',
                'hide_donation_amounts' => 'boolean',
                'hide_dedications' => 'boolean',
                'hide_donation_comments' => 'boolean',
                'hide_recurring_end_date' => 'boolean',
                'offer_dedication_postal_notifications' => 'boolean',
                'opt_in_checked_by_default' => 'boolean',
                'return_url' => 'string',
                'send_dedication_emails' => 'boolean'
            ],
            'indexes' => [
                'id'
            ]
        ],
        'credential_sets' => [
            'columns' => [
                'global_admin' => 'boolean',
                'campaign_manager' => 'boolean',
                'reporting_access' => 'boolean',
                'activity_wall' => 'boolean',
                'id' => 'integer',
                'member_id' => 'integer',
                'organization_id' => 'integer',
                'member' => 'object',
                'organization' => 'object'
            ],
            'indexes' => [
                'id',
                'member_id'
            ]
        ],
        'designations' => [
            'columns' => [
                'city' => 'string',
                'state' => 'string',
                'is_active' => 'boolean',
                'is_complete' => 'boolean',
                'external_reference_id' => 'string',
                'id' => 'integer',
                'organization_id' => 'integer',
                'name' => 'string',
                'description' => 'string',
                'postal_code' => 'string',
                'goal' => 'string',
                'start_time' => 'string',
                'end_time' => 'string',
                'created_at' => 'datetime',
                'is_default' => 'boolean',
                'updated_at' => 'datetime'
            ],
            'indexes' => [
                'id'
            ]
        ],
        'domain_slugs' => [
            'columns' => [
                'id' => 'integer',
                'domain_id' => 'integer',
                'fundraising_entity_id' => 'integer',
                'fundraising_entity_type' => 'string',
                'value' => 'string',
                'links_to_donation_page' => 'boolean',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
                'deleted_at' => 'datetime'
            ],
            'indexes' => [
                'id',
                'domain_id'
            ]
        ],
        'fundraising_pages' => [
            'columns' => [
                'status' => 'string',
                'fundraising_team_id' => 'integer',
                'campaign_id' => 'integer',
                'team_role' => 'string',
                'logo_id' => 'integer',
                'cover_photo_id' => 'integer',
                'thank_you_text' => 'string',
                'updated_at' => 'datetime',
                'raw_currency_code' => 'string',
                'raw_goal' => 'float',
                'commitment_id' => 'integer',
                'is_tribute' => 'boolean',
                'id' => 'integer',
                'member_id' => 'integer',
                'organization_id' => 'integer',
                'designation_id' => 'integer',
                'title' => 'string',
                'intro_text' => 'string',
                'thankyou_email_text' => 'string',
                'member_email_text' => 'string',
                'logo_url' => 'string',
                'goal' => 'float',
                'created_at' => 'datetime',
                'started_at' => 'datetime',
                'ended_at' => 'datetime',
                'alias' => 'string',
                'currency_code' => 'string',
                'canonical_url' => 'string',
                'supporter_id' => 'integer'
            ],
            'indexes' => [
                'id',
                'fundraising_team_id',
                'campaign_id',
                'member_id'
            ]
        ],
        'fundraising_teams' => [
            'columns' => [
                'team_lead_id' => 'integer',
                'status' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'campaign_id' => 'integer',
                'parent_id' => 'integer',
                'root_id' => 'integer',
                'logo_id' => 'integer',
                'cover_photo_id' => 'integer',
                'thank_you_text' => 'string',
                'updated_at' => 'datetime',
                'raw_currency_code' => 'string',
                'raw_goal' => 'string',
                'id' => 'string',
                'organization_id' => 'integer',
                'designation_id' => 'integer',
                'name' => 'string',
                'description' => 'string',
                'logo_url' => 'string',
                'goal' => 'float',
                'created_at' => 'datetime',
                'postal_code' => 'string',
                'cover_photo_url' => 'string',
                'currency_code' => 'string',
                'canonical_url' => 'string',
                'team_lead_supporter_id' => 'integer',
                'team_policy_id' => 'integer'
            ],
            'indexes' => [
                'id'
            ]
        ],
        'questions' => [
            'columns' => [
                'is_required' => 'boolean',
                'location' => 'string',
                'tag' => 'string',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
                'deleted_at' => 'datetime',
                'id' => 'integer',
                'campaign_id' => 'string',
                'label' => 'string',
                'type' => 'string',
                'product_id' => 'integer',
                'is_deleted' => 'boolean',
                'weight' => 'float'
            ],
            'indexes' => [
                'id'
            ]
        ],
        'recurring_donation_plans' => [
            'columns' => [
                'member_id' => 'integer',
                'raw_currency_code' => 'string',
                'raw_donation_amount' => 'float',
                'donation_amount' => 'float',
                'is_anonymous' => 'boolean',
                'status' => 'string',
                'fundraising_team_id' => 'integer',
                'cancel_by' => 'string',
                'first_name' => 'string',
                'last_name' => 'string',
                'address' => 'string',
                'address2' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'cc_card_exp' => 'string',
                'cc_last_four' => 'string',
                'cc_type' => 'string',
                'payment_gateway' => 'string',
                'fee_on_top' => 'boolean',
                'metadata' => 'object',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
                'is_gift_aid' => 'boolean',
                'applied_fot_percent' => 'float',
                'frequency' => 'string',
                'timezone_identifier' => 'string',
                'schedule' => 'array',
                'company_name' => 'string',
                'next_processing_date' => 'datetime',
                'payment_type' => 'string',
                'ach_last_four' => 'string',
                'hide_amount' => 'boolean',
                'recur_until' => 'string',
                'is_paused' => 'boolean',
                'pause_until' => 'string',
                'plan_paused_on_date' => 'datetime',
                'id' => 'integer',
                'organization_id' => 'integer',
                'designation_id' => 'integer',
                'started_at' => 'datetime',
                'pp_plan_id' => 'integer',
                'campaign_id' => 'integer',
                'fundraising_page_id' => 'integer',
                'canceled_at' => 'datetime',
                'postal_code' => 'string',
                'failed_at' => 'datetime',
                'cancel_reason_code' => 'string',
                'cancel_reason_text' => 'string',
                'currency_code' => 'string',
                'payment_provider_configuration_name' => 'string',
                'supporter_id' => 'integer',
                'is_donor_covered_fee' => 'boolean',
                'raw_initial_gross_amount' => 'float',
                'raw_adjustment_amount' => 'float',
                'applied_application_fee_percent' => 'float',
                'applied_processor_fee_percent' => 'float',
                'applied_raw_processor_fee_flat' => 'float',
                'applied_flex_rate_percent' => 'float',
                'raw_flex_rate_amount' => 'float',
                'raw_application_fee_amount' => 'float',
                'raw_processor_fee_amount' => 'float',
                'dcf_enabled' => 'boolean',
                'dcf_allowed' => 'boolean',
                'classy_mode_enabled' => 'boolean',
                'effective_fixed_fot_percent' => 'float'
            ],
            'indexes' => [
                'id',
                'member_id',
                'campaign_id',
            ]
        ],
        'registrations' => [
            'columns' => [
                'member_id' => 'integer',
                'first_name' => 'string',
                'last_name' => 'string',
                'email' => 'string',
                'phone' => 'string',
                'cell' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'company' => 'string',
                'website' => 'string',
                'blog' => 'string',
                'gender' => 'string',
                'date_of_birth' => 'string',
                'tshirt_size' => 'string',
                'attendee_id' => 'integer',
                'updated_at' => 'datetime',
                'status' => 'string',
                'commitment_id' => 'integer',
                'id' => 'string',
                'campaign_id' => 'integer',
                'address1' => 'string',
                'address2' => 'string',
                'postal_code' => 'string',
                'created_at' => 'datetime',
                'transaction_id' => 'integer',
                'fundraising_page_id' => 'integer',
                'transaction_item_id' => 'integer',
                'product_name' => 'string',
                'supporter_id' => 'integer',
                'transaction_last_four' => 'string',
                'transaction_payment_type' => 'string'
            ],
            'indexes' => [
                'id',
                'member_id',
                'campaign_id',
                'transaction_id'
            ]
        ],
        'source_tracking_codes' => [
            'columns' => [
                'id' => 'integer',
                'c_src' => 'string',
                'c_src2' => 'string',
                'referrer' => 'string',
                'event_type' => 'string',
                'trackable_id' => 'integer',
                'trackable_type' => 'string'
            ],
            'indexes' => [
                'id',
                'trackable_id'
            ]
        ],
        'supporters' => [
            'columns' => [
                'nickname' => 'string',
                'first_name' => 'string',
                'last_name' => 'string',
                'updated_at' => 'datetime',
                'metadata' => 'string',
                'id' => 'integer',
                'email_address' => 'string',
                'phone' => 'string',
                'location' => 'string',
                'address1' => 'string',
                'address2' => 'string',
                'city' => 'string',
                'state' => 'string',
                'country' => 'string',
                'postal_code' => 'string',
                'gender' => 'string',
                'member_id' => 'integer',
                'source_member_id' => 'integer',
                'source_organization_id' => 'integer',
                'source_campaign_id' => 'integer',
                'source_fundraising_page_id' => 'integer',
                'origin' => 'string',
                'opt_in' => 'boolean',
                'created_at' => 'datetime',
                'last_emailed_at' => 'datetime'
            ],
            'indexes' => [
                'id',
                'member_id'
            ]
        ],
        'ticket_types' => [
            'columns' => [
                'quantity_available' => 'integer',
                'quantity_sold' => 'integer',
                'quantity_reserved' => 'integer',
                'entries_per_ticket' => 'integer',
                'is_active' => 'boolean',
                'weight' => 'float',
                'deductible_amount' => 'float',
                'commitment_id' => 'integer',
                'id' => 'integer',
                'name' => 'string',
                'description' => 'string',
                'price' => 'float',
                'deductible_percent' => 'float',
                'started_at' => 'datetime',
                'ended_at' => 'datetime',
                'min_per_transaction' => 'integer',
                'max_per_transaction' => 'integer',
                'created_at' => 'datetime',
                'updated_at' => 'datetime',
                'is_classy_mode' => 'boolean',
                'campaign_id' => 'integer',
                'org_percent' => 'float',
                'commitment' => 'string'
            ],
            'indexes' => [
                'id',
                'campaign_id'
            ]
        ],
        'transactions' => [
            'columns' => [
                'billing_city' => 'string',
                'billing_state' => 'string',
                'billing_country' => 'string',
                'payment_method' => 'string',
                'company_name' => 'string',
                'in_honor_of' => 'string',
                'fundraising_team_id' => 'integer',
                'is_anonymous' => 'boolean',
                'payment_gateway' => 'string',
                'browser_info' => 'string',
                'acknowledgements_count' => 'string',
                'fee_on_top' => 'string',
                'account_type' => 'string',
                'institution' => 'string',
                'account_number' => 'string',
                'raw_currency_code' => 'string',
                'charged_currency_code' => 'string',
                'charged_total_gross_amount' => 'float',
                'charged_classy_fees_amount' => 'float',
                'charged_pp_fees_amount' => 'float',
                'tax_entity_id' => 'integer',
                'charged_at' => 'datetime',
                'is_gift_aid' => 'boolean',
                'applied_fot_percent' => 'float',
                'payment_source' => 'string',
                'hide_amount' => 'boolean',
                'processor_decline_code' => 'string',
                'is_reprocess' => 'boolean',
                'id' => 'integer',
                'member_id' => 'integer',
                'member_name' => 'string',
                'member_country' => 'string',
                'member_phone' => 'string',
                'member_email_address' => 'string',
                'billing_first_name' => 'string',
                'billing_last_name' => 'string',
                'billing_address1' => 'string',
                'billing_address2' => 'string',
                'billing_postal_code' => 'string',
                'card_type' => 'string',
                'updated_at' => 'datetime',
                'purchased_at' => 'datetime',
                'status' => 'string',
                'refunded_at' => 'datetime',
                'pp_reference_id' => 'string',
                'pp_transaction_id' => 'string',
                'pp_response' => 'string',
                'pp_fees_amount' => 'float',
                'payment_type' => 'string',
                'total_gross_amount' => 'float',
                'donation_net_amount' => 'float',
                'donation_gross_amount' => 'float',
                'overhead_net_amount' => 'float',
                'fees_amount' => 'float',
                'classy_fees_amount' => 'float',
                'organization_id' => 'integer',
                'designation_id' => 'integer',
                'campaign_id' => 'integer',
                'fundraising_page_id' => 'integer',
                'recurring_donation_plan_id' => 'integer',
                'comment' => 'string',
                'parent_transaction_id' => 'integer',
                'created_at' => 'datetime',
                'card_last_four' => 'string',
                'card_expiration' => 'string',
                'adjustment_amount' => 'float',
                'promo_code_code' => 'string',
                'applied_application_fee_percent' => 'float',
                'applied_processor_fee_percent' => 'float',
                'applied_raw_processor_fee_flat' => 'float',
                'applied_flex_rate_percent' => 'float',
                'charged_fees_amount' => 'float',
                'currency_code' => 'string',
                'frequency' => 'string',
                'is_donor_covered_fee' => 'boolean',
                'metadata' => 'object',
                'raw_donation_gross_amount' => 'float',
                'raw_initial_gross_amount' => 'float',
                'initial_gross_amount' => 'float',
                'raw_adjustment_amount' => 'float',
                'raw_overhead_net_amount' => 'float',
                'raw_total_gross_amount' => 'float',
                'raw_flex_rate_amount' => 'float',
                'flex_rate_amount' => 'float',
                'raw_application_fee_amount' => 'float',
                'application_fee_amount' => 'float',
                'raw_processor_fee_amount' => 'float',
                'processor_fee_amount' => 'float',
                'reprocess_attempts' => 'integer',
                'supporter_id' => 'integer',
                'context' => 'object'
            ],
            'indexes' => [
                'id',
                'parent_transaction_id',
                'member_id',
                'campaign_id'
            ]
        ]
    ];
}
