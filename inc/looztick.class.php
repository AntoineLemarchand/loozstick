
<?php
/**
 * ---------------------------------------------------------------------
 * ITSM-NG
 * Copyright (C) 2022 ITSM-NG and contributors.
 *
 * https://www.itsm-ng.org
 *
 * based on GLPI - Gestionnaire Libre de Parc Informatique
 * Copyright (C) 2003-2014 by the INDEPNET Development Team.
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of ITSM-NG.
 *
 * ITSM-NG is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * ITSM-NG is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with ITSM-NG. If not, see <http://www.gnu.org/licenses/>.
 * ---------------------------------------------------------------------
 */

class PluginLooztickLooztick extends CommonDBTM
{
    const LOOZTIK_ENDPOINT = "https://looztick.fr/api";
    static $rightname = "plugin_looztick_looztick";

    static function getMenuContent(): array
    {
        $menu = [
            'title' => 'Looztick',
            'page' => Plugin::getPhpDir('looztick', false) . '/front/looztick.form.php',
            'icon' => 'fas fa-qrcode'
        ];

        return $menu;
    }

    static function getConfig(): array
    {
        global $DB;

        $query = "SELECT * FROM glpi_plugin_looztick_config WHERE id = 1";
        $result = $DB->query($query);
        $config = iterator_to_array($result)[0];
        return $config;
    }

    static function sendQuery(string $method = 'GET', string $uri = '/', array $data = [])
    {
        $apiKey = self::getConfig()['api_key'] ?? '';
        $result = [];
        foreach (explode(',', $apiKey) as $key) {
            $content = $data + ['key' => $key];
            $url = self::LOOZTIK_ENDPOINT . $uri;
            $opts = [
                'http' => [
                    'method' => $method,
                    'header' => 'Content-Type: application/json',
                    'content' => json_encode($content)
                ]
            ];
            $context = stream_context_create($opts);
            $result = array_merge($result, json_decode(file_get_contents($url, false, $context), true));
            if ($result['control'] != "ok") {
                break;
            }
        }

        return $result;
    }

    static function updateQrCodes()
    {
        global $DB;
        $qrcodes = self::sendQuery('GET', '/qrcodes/');
        $table = self::getTable();
        if (!isset($qrcodes['qrcodes']) || count($qrcodes['qrcodes']) == 0) {
            return;
        }
    
        $query = "REPLACE INTO `$table` 
                  (id, item, firstname, lastname, mobile, friendmobile, countrycode, email, activated) 
                  VALUES ";
    
        $values = array();
    
        foreach ($qrcodes['qrcodes'] as $qrcode) {
            $values[] = "('{$qrcode['id']}', '{$qrcode['id_client']}', '{$qrcode['firstname']}', '{$qrcode['lastname']}', '{$qrcode['mobile']}', '{$qrcode['friendmobile']}', '{$qrcode['countrycode']}', '{$qrcode['email']}', '{$qrcode['activated']}')";
        }
    
        $query .= implode(', ', $values) . ";";
    
        $DB->query($query);
    }
    

    static function testApiConnection(): bool
    {
        $response = PluginLooztickLooztick::sendQuery("POST");
        return $response['control'] == "ok";
    }

    static function getQrCodes(): array
    {
        global $DB;
        $query = "SELECT * FROM glpi_plugin_looztick_loozticks";
        $result = $DB->query($query);
        return iterator_to_array($result);
    }

    static function unlink(): bool
    {
        global $DB;
        $query = "UPDATE glpi_plugin_looztick_loozticks SET item = '', activated = 0 WHERE id = {$_POST['id']}";
        $DB->query($query);
        self::sendQuery('POST', '/update/', ['qrcode' => $_POST['id'], 'activated' => 0]);
        return true;
    }

    function showForm()
    {
        global $DB;

        $api_key_label = __("API Key");
        $form_action = Plugin::getWebDir("looztick")."/front/looztick.form.php?id=".$this->fields["id"];
        
        $defaultValues = [
            'First name' => 'firstname',
            'Last name' => 'lastname',
            'Mobile' => 'mobile',
            'Second mobile' => 'friendmobile',
            'Country code' => 'countrycode',
            'Email' => 'email',
        ];

        $item = explode('_', $this->fields['item']);
        $itemUrl = $item[0]::getFormURL()."?id=".$item[1];
        $activatedLabel = __('Activated');
        $link = <<<HTML
        <a href={$itemUrl}>{$activatedLabel}</a>
        HTML;

        $form = [
            'action' => $form_action,
            'submit' => __('Save'),
            'content' => [
                'Looztick QR Code' => [
                    'visible' => true,
                    'inputs' => [
                        'action' => [
                            'name' => 'action',
                            'type' => 'hidden',
                            'value' => 'update',
                        ],
                        $link => [
                            'type' => 'checkbox',
                            'value' => $this->fields['activated'],
                            'name' => 'activated',
                            'disabled' => true,
                        ],
                        'Code' => [
                            'type' => 'text',
                            'value' => $this->fields['id'],
                            'name' => 'api_key',
                            'disabled' => true,
                        ],
                    ]
                ] 
            ]
        ];
        foreach ($defaultValues as $label => $name) {
            $form['content']['Looztick QR Code']['inputs'] += [ $label => [
                'type' => 'text',
                'value' => $this->fields[$name],
                'name' => $name,
            ]];
        }
        include_once GLPI_ROOT . '/ng/form.utils.php';
        renderTwigForm($form);
    }

    function rawSearchOptions()
    {
        $tab = [];
        $tab[] = [
            'id' => 1,
            'table' => self::getTable(),
            'name' => __("QR Code"),
            'field' => 'id',
            'datatype' => 'itemlink'
        ];
        $tab[] = [
            'id' => 2,
            'table' => self::getTable(),
            'name' => __("First name"),
            'field' => 'firstname',
        ];
        $tab[] = [
            'id' => 3,
            'table' => self::getTable(),
            'name' => __("Last name"),
            'field' => 'lastname',
        ];
        $tab[] = [
            'id' => 4,
            'table' => self::getTable(),
            'name' => __("Mobile"),
            'field' => 'mobile',
        ];
        $tab[] = [
            'id' => 5,
            'table' => self::getTable(),
            'name' => __("Friend mobile"),
            'field' => 'friendmobile',
        ];
        $tab[] = [
            'id' => 6,
            'table' => self::getTable(),
            'name' => __("Country code"),
            'field' => 'countrycode',
        ];
        $tab[] = [
            'id' => 7,
            'table' => self::getTable(),
            'name' => __("Email"),
            'field' => 'email',
        ];
        $tab[] = [
            'id' => 8,
            'table' => self::getTable(),
            'name' => __("Activated"),
            'field' => 'activated',
            'massiveaction' => false,
        ];
        return $tab;
    }

    function getTabNameForItem(CommonGLPI $item, $withtemplate = 0)
    {
        return "Looztick";
    }

    static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0)
    {
        $qrcodes = self::getQrCodes();
        $currentQrcode = array_filter($qrcodes, function ($qrcode) use ($item) {
            return $qrcode['item'] == $item->getType(). '_' .$item->getID();
        });
        $qrcodeAjaxEndpoint = Plugin::getWebDir('looztick') . '/ajax/qrcode.php';

        $form = [
            'action' => Plugin::getWebDir('looztick') . '/front/looztick.form.php',
            'submit' => 'Link',
            'content' => [
                '' => [
                    'visible' => true,
                    'inputs' => [
                        "QR code" => [
                            'name' => 'qrcode',
                            'id' => 'looztick_qrcode_dropdown',
                            'type' => 'select',
                            'values' => array_column($qrcodes, 'id', 'id'),
                            'value' => array_values($currentQrcode)[0]['id'] ?? null,
                            count($currentQrcode) != 0 ? 'disabled' : '' => true,
                            'hooks' => [
                                'change' => <<<JS
                                    $.ajax({
                                        url: '{$qrcodeAjaxEndpoint}',
                                        method: 'POST',
                                        data: {
                                            id: $('#looztick_qrcode_dropdown').val()
                                        },
                                        success: function(data) {
                                            $('#looztick_firstname').val(data.firstname);
                                            $('#looztick_lastname').val(data.lastname);
                                            $('#looztick_mobile').val(data.mobile);
                                            $('#looztick_friendmobile').val(data.friendmobile);
                                            $('#looztick_countrycode').val(data.countrycode);
                                            $('#looztick_email').val(data.email);
                                        }
                                    });
                                JS,
                            ],
                            'actions' => count($currentQrcode) != 0 ? ['unlink' => [
                                    'icon' => 'fas fa-unlink',
                                    'onClick' => <<<JS
                                        $.ajax({
                                            url: '{$qrcodeAjaxEndpoint}',
                                            method: 'POST',
                                            data: {
                                                action: 'unlink',
                                                id: $('#looztick_qrcode_dropdown').val(),
                                            },
                                        });
                                        window.location.reload();
                                    JS,
                                ]
                            ] : []
                        ],
                        "First name" => [
                            'name' => 'firstname',
                            'id' => 'looztick_firstname',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['firstname'] ?? null,
                        ],
                        "Last name" => [
                            'name' => 'lastname',
                            'id' => 'looztick_lastname',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['lastname'] ?? null,
                        ],
                        "Mobile" => [
                            'name' => 'mobile',
                            'id' => 'looztick_mobile',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['mobile'] ?? null,
                        ],
                        "Friend mobile" => [
                            'name' => 'friendmobile',
                            'id' => 'looztick_friendmobile',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['friendmobile'] ?? null,
                        ],
                        "Country code" => [
                            'name' => 'countrycode',
                            'id' => 'looztick_countrycode',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['countrycode'] ?? null,
                        ],
                        "Email" => [
                            'name' => 'email',
                            'id' => 'looztick_email',
                            'type' => 'text',
                            'value' => array_values($currentQrcode)[0]['email'] ?? null,
                        ],
                        'action' => [
                            'name' => 'action',
                            'type' => 'hidden',
                            'value' => 'update',
                        ],
                        'item' => [
                            'name' => 'item',
                            'type' => 'hidden',
                            'value' => $item->getType(). '_' . $item->getID(),
                        ],
                        'activated' => [
                            'name' => 'activated',
                            'type' => 'hidden',
                            'value' => 1,
                        ],
                    ]
                ]
            ]
        ];
        renderTwigForm($form);
        return true;
    }
}
