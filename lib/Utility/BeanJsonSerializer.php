<?php
/**
 * SugarCRM Community Edition is a customer relationship management program developed by
 * SugarCRM, Inc. Copyright (C) 2004-2013 SugarCRM Inc.
 *
 * SuiteCRM is an extension to SugarCRM Community Edition developed by SalesAgility Ltd.
 * Copyright (C) 2011 - 2018 SalesAgility Ltd.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY SUGARCRM, SUGARCRM DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE. See the GNU Affero General Public License for more
 * details.
 *
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 *
 * You can contact SugarCRM, Inc. headquarters at 10050 North Wolfe Road,
 * SW2-130, Cupertino, CA 95014, USA. or at email address contact@sugarcrm.com.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * SugarCRM" logo and "Supercharged by SuiteCRM" logo. If the display of the logos is not
 * reasonably feasible for technical reasons, the Appropriate Legal Notices must
 * display the words "Powered by SugarCRM" and "Supercharged by SuiteCRM".
 */

namespace SuiteCRM\Utility;

if (!defined('sugarEntry') || !sugarEntry) {
    die('Not A Valid Entry Point');
}

use InvalidArgumentException;
use Person;
use SugarBean;

/**
 * Class BeanJsonSerializer converts a SugarBean into a pretty JSON Document.
 */
class BeanJsonSerializer
{
    /**
     * Fields we don't want to be serialized.
     */
    const GARBAGE = [
        'deleted', 'photo', 'do_not_call', 'lawful_basis', 'date_reviewed', 'lawful_basis_source',
        'c_accept_status_fields', 'm_accept_status_fields', 'e_invite_status_fields', 'e_accept_status_fields',
        'jjwg_maps_lng_c', 'jjwg_maps_lat_c', 'jjwg_maps_geocode_status_c', 'jjwg_maps_address_c',
        'opportunity_role_fields', 'id_c', 'joomla_account_id', 'portal_account_disabled', 'portal_user_type',
        'user_hash', 'pwd_last_changed', 'authenticate_id', 'sugar_login', 'is_admin', 'external_auth_only',
        'receive_notifications', 'status', 'portal_only', 'show_on_employees', 'is_group', 'factor_auth',
        'factor_auth_interface', 'securitygroup_noninher_fields', 'system_generated_password', 'full_name',
        'modified_by_name_mod', 'created_by_name_mod', 'assigned_user_name_mod', 'parent_name_mod',
        'report_to_name_mod', 'campaign_name_mod', 'email_opt_out'
    ];

    /**
     * Converts a SugarBean to a nested, standardised, cleaned JSON string.
     *
     * @param \SugarBean $bean            the bean to serialise
     * @param bool       $hideEmptyValues removes fields with empty (`''` or `null`) values.
     * @param bool       $pretty          to make *very* pretty formatted.
     *
     * @return string
     */
    public static function serialize($bean, $hideEmptyValues = true, $pretty = false)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_UNESCAPED_UNICODE;

        if ($pretty) {
            $flags = $flags | JSON_PRETTY_PRINT;
        }

        return json_encode(self::toArray($bean, $hideEmptyValues), $flags);
    }

    /**
     * Converts a SugarBean to a nested, standardised, cleaned associative array.
     *
     * The `$loadRelationships` option allows to choose whether to load the bean's relationship or not.
     * This has a serious impact on performance if enabled (~70% slower). Also, I suspect no more fields are detected.
     * Keep it disabled.
     *
     * @param \SugarBean $bean              the bean to serialise
     * @param bool       $hideEmptyValues   removes fields with empty (`''` or `null`) values.
     * @param bool       $loadRelationships whether to load the bean relationship
     *
     * @deprecated
     * @return array
     */
    public static function toArrayOld($bean, $hideEmptyValues = true, $loadRelationships = false)
    {
        if ($loadRelationships) {
            $bean->load_relationships();
        }

        // creates an associative array with all the raw values that might need serialisation
        if (isset($bean->fetched_row) && is_array($bean->fetched_row)) {
            $keys = array_keys($bean->fetched_row);
            if ($bean->fetched_rel_row && is_array($bean->fetched_rel_row)) {
                $keys = array_merge($keys, array_keys($bean->fetched_rel_row));
            }
            $fields = $bean;
        } elseif (isset($bean->column_fields) && is_array($bean->column_fields)) {
            $keys = $bean->column_fields;
            $fields = $bean;
        } else {
            $fields = get_object_vars($bean);
            $keys = array_keys($fields);
        }

        $prettyBean = [];

        // does a number of checks and validation to standardise the format of fields, especially adding nesting of values
        foreach ($keys as $key) {
            if (in_array($key, self::GARBAGE)) {
                continue;
            }

            if (is_array($fields)) {
                $value = $fields[$key];
            } elseif (is_object($fields)) {
                if (isset($fields->$key)) {
                    $value = $fields->$key;
                } else {
                    $value = null;
                }
            } else {
                throw new InvalidArgumentException('Wrong parameter type provided');
            }

            // fail safe to prevent objects to be forcefully casted into strings
            if (is_array($value) || is_object($value) || is_resource($value)) {
                continue;
            }

            if (is_string($value)) {
                $value = mb_convert_encoding($value, 'UTF-8', 'HTML-ENTITIES');
                $value = trim($value);
            }

            if ($hideEmptyValues && ($value === null || $value === '')) {
                continue;
            }

            //region metas
            if ($key === 'date_entered') {
                $prettyBean['meta']['created']['date'] = $value;
                continue;
            }

            if ($key === 'created_by') {
                $prettyBean['meta']['created']['user_id'] = $value;
                continue;
            }

            if ($key === 'date_modified') {
                $prettyBean['meta']['modified']['date'] = $value;
                continue;
            }

            if ($key === 'modified_user_id') {
                $prettyBean['meta']['modified']['user_id'] = $value;
                continue;
            }

            if ($key === 'assigned_user_id') {
                $prettyBean['meta']['assigned']['user_id'] = $value;
                continue;
            }

            if ($key === 'modified_by_name') {
                $prettyBean['meta']['modified']['user_name'] = $value;
                continue;
            }

            if ($key === 'created_by_name') {
                $prettyBean['meta']['created']['user_name'] = $value;
                continue;
            }

            if ($key === 'assigned_user_name') {
                $prettyBean['meta']['assigned']['user_name'] = $value;
                continue;
            }

            if ($key === 'assigned_user_name_owner') {
                $prettyBean['meta']['assigned']['owner_name'] = $value;
                continue;
            }
            //endregion

            //region assistant
            if ($key === 'assistant') {
                $prettyBean['assistant']['name'] = $value;
                continue;
            }

            if ($key === 'assistant_phone') {
                $prettyBean['assistant']['phone'] = $value;
                continue;
            }
            // endregion

            //region reportTo
            if ($key === 'reports_to_id') {
                $prettyBean['reports_to']['id'] = $value;
                continue;
            }

            if ($key === 'report_to_name') {
                $prettyBean['reports_to']['name'] = $value;
                continue;
            }

            if ($key === 'reports_to_name') {
                $prettyBean['reports_to']['name'] = $value;
                continue;
            }
            //endregion

            //region campaign
            if ($key === 'campaign_id') {
                $prettyBean['campaign']['id'] = $value;
                continue;
            }

            if ($key === 'campaign_name') {
                $prettyBean['campaign']['name'] = $value;
                continue;
            }
            //endregion

            //region name
            if ($key === 'name') {
                self::fixName($bean, $prettyBean);
                continue;
            }

            if ($key === 'first_name') {
                $prettyBean['name']['first'] = $value;
                continue;
            }

            if ($key === 'last_name') {
                $prettyBean['name']['last'] = $value;
                continue;
            }

            if ($key === 'salutation') {
                $prettyBean['name']['salutation'] = $value;
                continue;
            }
            //endregion

            //region account
            if ($key === 'account_id') {
                $prettyBean['account']['id'] = $value;
                continue;
            }

            if ($key === 'account_name') {
                $prettyBean['account']['name'] = $value;
                continue;
            }

            if ($key === 'title') {
                $prettyBean['account']['title'] = $value;
                continue;
            }

            if ($key === 'department') {
                $prettyBean['account']['department'] = $value;
                continue;
            }
            //endregion

            //region parent
            if ($key === 'parent_id') {
                $prettyBean['parent']['id'] = $value;
                continue;
            }

            if ($key === 'parent_name') {
                $prettyBean['parent']['name'] = $value;
                continue;
            }
            //endregion

            //region messenger
            if ($key === 'messenger_id') {
                $prettyBean['messenger']['id'] = $value;
                continue;
            }

            if ($key === 'messenger_type') {
                $prettyBean['messenger']['type'] = $value;
                continue;
            }
            //endregion

            //region emails
            if ($key === 'email') {
                // TODO CHECK THIS
                continue;
            }

            if (preg_match('/^email([0-9]+)$/', $key)) {
                $prettyBean['email'][] = $value;
                continue;
            }
            //endregion

            //region phone
            if (preg_match('/^phone\_([a-z_]+)$/', $key, $matches)) {
                $prettyBean['phone'][$matches[1]] = self::sanitizePhone($value);
                continue;
            }
            //endregion

            //region address
            if (preg_match('/^address\_([a-z_]+)$/', $key, $matches)) {
                $prettyBean['address']['primary'][$matches[1]] = $value;
                continue;
            }

            if (preg_match('/^([a-z]+)\_address\_([a-z_]+)$/', $key, $matches)) {
                $prettyBean['address'][$matches[1]][$matches[2]] = $value;
                continue;
            }
            //endregion

            $prettyBean[$key] = $value;
        }

        return $prettyBean;
    }

    /**
     * Converts a SugarBean to a nested, standardised, cleaned associative array.
     *
     * The `$loadRelationships` option allows to choose whether to load the bean's relationship or not.
     * This has a serious impact on performance if enabled (~70% slower). Also, I suspect no more fields are detected.
     * Keep it disabled.
     *
     * @param \SugarBean $bean              the bean to serialise
     * @param bool       $hideEmptyValues   removes fields with empty (`''` or `null`) values.
     * @param bool       $loadRelationships whether to load the bean relationship
     *
     * @return array
     */
    public static function toArray(SugarBean $bean, $hideEmptyValues = true, $loadRelationships = false)
    {
        if ($loadRelationships) {
            $bean->load_relationships();
        }

        // creates an associative array with all the raw values that might need serialisation
        if (isset($bean->fetched_row) && is_array($bean->fetched_row)) {
            $keys = array_keys($bean->fetched_row);
            if ($bean->fetched_rel_row && is_array($bean->fetched_rel_row)) {
                $keys = array_merge($keys, array_keys($bean->fetched_rel_row));
            }
            $fields = get_object_vars($bean);
        } else if (isset($bean->column_fields) && is_array($bean->column_fields)) {
            $keys = $bean->column_fields;
            $fields = $bean;
        } else {
            $fields = get_object_vars($bean);
            $keys = array_keys($fields);
        }

        $mapper = new ArrayMapper();

        $mapper->setMappable($fields);
        $mapper->loadYaml(__DIR__ . '/BeanJsonSerializer.yml');
        $mapper->setHideEmptyValues($hideEmptyValues);

        $prettyBean = $mapper->map($keys);

        self::fixPhone($prettyBean);
        self::fixName($bean, $prettyBean);

        return $prettyBean;
    }

    /**
     * Standardizes name structure to avoid collision.
     *
     * @param SugarBean $bean
     * @param           $prettyBean
     */
    private static function fixName(SugarBean $bean, &$prettyBean)
    {
        if (is_subclass_of($bean, Person::class)
            || (isset($bean->module_name) && $bean->module_name === 'Contacts')) {
            if (isset($bean->first_name)) {
                $prettyBean['name']['first'] = $bean->first_name;
            }
            if (isset($bean->last_name)) {
                $prettyBean['name']['last'] = $bean->last_name;
            }
        } else {
            $prettyBean['name'] = ['name' => $bean->name];
        }
    }

    /**
     * Applies sanitizePhone() to all the phones in the serialisation array.
     *
     * @param $prettyBean
     */
    private static function fixPhone(&$prettyBean)
    {
        if (isset($prettyBean['phone'])) {
            foreach ($prettyBean['phone'] as &$phone) {
                $phone = self::sanitizePhone($phone);
            }
        }
    }

    /**
     * Strips non-numeric characters from a phone number (apart from `+`), to improve search results.
     *
     * @param $phone
     * @return null|string|string[]
     */
    public static function sanitizePhone($phone)
    {
        return $phone = preg_replace('/[^0-9+]/', '', $phone);
    }
}