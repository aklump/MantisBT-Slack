<?php

/**
 * Slack Integration
 * Copyright (C) 2014 Karim Ratib (karim.ratib@gmail.com)
 *
 * Slack Integration is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License 2
 * as published by the Free Software Foundation.
 *
 * Slack Integration is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Slack Integration; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301,
 * USA or see http://www.gnu.org/licenses/.
 */
class SlackPlugin extends MantisPlugin {
  var $skip = FALSE;

  function register() {
    $this->name = plugin_lang_get('title');
    $this->description = plugin_lang_get('description');
    $this->page = 'config';
    $this->version = '1.0';
    $this->requires = array(
      'MantisCore' => '1.3.0',
    );
    $this->author = 'Aaron Klump';
    $this->contact = 'sourcecode@intheloftstudios.com';
    $this->url = 'http://intheloftstudios.com';
  }

  function install() {
    if (version_compare(PHP_VERSION, '5.3.0', '<')) {
      plugin_error(ERROR_PHP_VERSION, ERROR);

      return FALSE;
    }
    if (!extension_loaded('curl')) {
      plugin_error(ERROR_NO_CURL, ERROR);

      return FALSE;
    }

    return TRUE;
  }

  function config() {
    return array(
      'url_webhooks'    => array(),
      'url_webhook'     => '',
      'bot_name'        => 'mantis',
      'bot_icon'        => '',
      'skip_bulk'       => TRUE,
      'link_names'      => FALSE,
      'user_aliases'    => array(),
      'channels'        => array(),
      'default_channel' => '#general',
      'columns'         => array(
        'status',
        'handler_id',
        'target_version',
        'priority',
        'severity',
      ),
    );
  }

  function hooks() {
    return array(
      'EVENT_REPORT_BUG'       => 'bug_report_update',
      'EVENT_UPDATE_BUG'       => 'bug_report_update',
      'EVENT_BUG_DELETED'      => 'bug_deleted',
      'EVENT_BUG_ACTION'       => 'bug_action',
      'EVENT_BUGNOTE_ADD'      => 'bugnote_add_edit',
      'EVENT_BUGNOTE_EDIT'     => 'bugnote_add_edit',
      'EVENT_BUGNOTE_DELETED'  => 'bugnote_deleted',
      'EVENT_BUGNOTE_ADD_FORM' => 'bugnote_add_form',
    );
  }

  function bugnote_add_form($event, $bug_id) {
    if ($_SERVER['PHP_SELF'] !== '/bug_update_page.php') {
      return;
    }

    echo '<tr><td class="center" colspan="6">';
    echo '<input ', helper_get_tab_index(), ' name="slack_skip" type="checkbox" >' . plugin_lang_get('skip') . '</input>';
    echo '</td></tr>';
  }

  function bug_action($event, $action, $bug_id) {
    $this->skip = $this->skip || gpc_get_bool('slack_skip') || plugin_config_get('skip_bulk');

    if ($action !== 'DELETE') {
      $bug = bug_get($bug_id);
      $this->bug_report_update('EVENT_UPDATE_BUG', $bug, $bug_id);
    }
  }

  function bug_report_update($event, $old_bug, $new_bug) {
    $this->skip = $this->skip || gpc_get_bool('slack_skip');

    $bug = is_object($new_bug) ? $new_bug : $old_bug;

    $project = project_get_name($bug->project_id);
    $url = string_get_bug_view_url_with_fqdn($bug->id);
    $summary = $this->clean_summary($bug);
    $reporter = '@' . user_get_name(auth_get_current_user_id());
    $reporter = $this->get_user_alias($reporter);
    $lang_event = $event === 'EVENT_REPORT_BUG' ? 'bug_created' : 'bug_updated';

    // If the status is closed then we will change the event
    
    // TODO This should not be hardcoded as 90
    if ($old_bug->status !== $bug->status && $bug->status === 90) {
      $lang_event = 'bug_closed';
    }

    $msg = sprintf(plugin_lang_get($lang_event),
      $reporter, $url, $summary
    );
    $attachment = $this->get_attachment($bug);

    $this->notify($msg, $this->get_webhook($project), $this->get_channel($project), $attachment);
  }

  function clean_summary($bug) {
    $summary = bug_format_id($bug->id) . ': ' . string_display_line_links($bug->summary);

    return strip_tags(html_entity_decode($summary));
  }

  function get_user_alias($mantis_user) {
    $users = plugin_config_get('user_aliases', array());
    $mantis_user = ltrim($mantis_user, '@');

    return isset($users[$mantis_user]) ? $users[$mantis_user] : $mantis_user;;
  }

  function notify($msg, $webhook, $channel, $attachment = FALSE) {
    if ($this->skip) {
      return;
    }
    if (empty($channel)) {
      return;
    }
    if (empty($webhook)) {
      return;
    }

    $ch = curl_init();
    // @see https://my.slack.com/services/new/incoming-webhook
    // remove istance and token and add plugin_Slack_url config , see configurations with url above
    $url = sprintf('%s', trim($webhook));
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $payload = array(
      'channel'    => $channel,
      'username'   => plugin_config_get('bot_name'),
      'text'       => $msg,
      'link_names' => plugin_config_get('link_names'),
    );
    $bot_icon = trim(plugin_config_get('bot_icon'));
    if (empty($bot_icon)) {
      $payload['icon_url'] = 'https://raw.githubusercontent.com/aklump/MantisBT-Slack/master/itls_logo.png';
    }
    elseif (preg_match('/^:[a-z0-9_\-]+:$/i', $bot_icon)) {
      $payload['icon_emoji'] = $bot_icon;
    }
    elseif ($bot_icon) {
      $payload['icon_url'] = trim($bot_icon);
    }
    if ($attachment) {
      $payload['attachments'] = array($attachment);
    }
    $data = array('payload' => json_encode($payload));
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    $result = curl_exec($ch);
    if ($result !== 'ok') {
      trigger_error($result, E_USER_WARNING);
      plugin_error('ERROR_CURL', E_USER_ERROR);
    }
    curl_close($ch);
  }

  function get_webhook($project) {
    $webhooks = plugin_config_get('url_webhooks');

    return array_key_exists($project, $webhooks) ? $webhooks[$project] : plugin_config_get('url_webhook');
  }

  function get_channel($project) {
    $channels = plugin_config_get('channels');

    return array_key_exists($project, $channels) ? $channels[$project] : plugin_config_get('default_channel');
  }

  function get_attachment($bug) {

    $attachment = array('fallback' => '');
    $t_columns = (array) plugin_config_get('columns');
    foreach ($t_columns as $t_column) {
      $title = column_get_title($t_column);
      $value = $this->format_value($bug, $t_column);

      if (strpos($value, '@') === 0) {
        $value = $this->get_user_alias($value);
      }

      if ($title && $value) {
        $attachment['fallback'] .= $title . ': ' . $value . "\n";
        $attachment['fields'][] = array(
          'title' => $title,
          'value' => $value,
          'short' => !column_is_extended($t_column),
        );
      }
    }

    // Add in the followers with @mentions.
    if (($followers = bug_get_monitors($bug->id))) {
      $title = 'Followers';
      $value = array();
      foreach ($followers as $follower_id) {
        $value[] = $this->get_user_alias(user_get_name($follower_id));
      }
      $value = implode(', ', $value);
      $attachment['fallback'] .= $title . ': ' . $value . "\n";
      $attachment['fields'][] = array(
        'title' => $title,
        'value' => $value,
        'short' => TRUE,
      );
    }

    return $attachment;
  }

  function format_value($bug, $field_name) {
    $self = $this;
    $values = array(
      'id'                     => function ($bug) {
        return sprintf('<%s|%s>', string_get_bug_view_url_with_fqdn($bug->id), $bug->id);
      },
      'project_id'             => function ($bug) {
        return project_get_name($bug->project_id);
      },
      'reporter_id'            => function ($bug) {
        return '@' . user_get_name($bug->reporter_id);
      },
      'handler_id'             => function ($bug) {
        return empty($bug->handler_id) ? plugin_lang_get('no_user') : ('@' . user_get_name($bug->handler_id));
      },
      'duplicate_id'           => function ($bug) {
        return sprintf('<%s|%s>', string_get_bug_view_url_with_fqdn($bug->duplicate_id), $bug->duplicate_id);
      },
      'priority'               => function ($bug) {
        return get_enum_element('priority', $bug->priority);
      },
      'severity'               => function ($bug) {
        return get_enum_element('severity', $bug->severity);
      },
      'reproducibility'        => function ($bug) {
        return get_enum_element('reproducibility', $bug->reproducibility);
      },
      'status'                 => function ($bug) {
        return get_enum_element('status', $bug->status);
      },
      'resolution'             => function ($bug) {
        return get_enum_element('resolution', $bug->resolution);
      },
      'projection'             => function ($bug) {
        return get_enum_element('projection', $bug->projection);
      },
      'category_id'            => function ($bug) {
        return category_full_name($bug->category_id, FALSE);
      },
      'eta'                    => function ($bug) {
        return get_enum_element('eta', $bug->eta);
      },
      'view_state'             => function ($bug) {
        return $bug->view_state == VS_PRIVATE ? lang_get('private') : lang_get('public');
      },
      'sponsorship_total'      => function ($bug) {
        return sponsorship_format_amount($bug->sponsorship_total);
      },
      'os'                     => function ($bug) {
        return $bug->os;
      },
      'os_build'               => function ($bug) {
        return $bug->os_build;
      },
      'platform'               => function ($bug) {
        return $bug->platform;
      },
      'version'                => function ($bug) {
        return $bug->version;
      },
      'fixed_in_version'       => function ($bug) {
        return $bug->fixed_in_version;
      },
      'target_version'         => function ($bug) {
        return $bug->target_version;
      },
      'build'                  => function ($bug) {
        return $bug->build;
      },
      'summary'                => function ($bug) use ($self) {
        return $self->clean_summary($bug);
      },
      'last_updated'           => function ($bug) {
        return date(config_get('short_date_format'), $bug->last_updated);
      },
      'date_submitted'         => function ($bug) {
        return date(config_get('short_date_format'), $bug->date_submitted);
      },
      'due_date'               => function ($bug) {
        return date(config_get('short_date_format'), $bug->due_date);
      },
      'description'            => function ($bug) {
        return string_display_links($bug->description);
      },
      'steps_to_reproduce'     => function ($bug) {
        return string_display_links($bug->steps_to_reproduce);
      },
      'additional_information' => function ($bug) {
        return string_display_links($bug->additional_information);
      },
    );
    // Discover custom fields.
    $t_related_custom_field_ids = custom_field_get_linked_ids($bug->project_id);
    foreach ($t_related_custom_field_ids as $t_id) {
      $t_def = custom_field_get_definition($t_id);
      $values['custom_' . $t_def['name']] = function ($bug) use ($t_id) {
        return custom_field_get_value($t_id, $bug->id);
      };
    }
    if (isset($values[$field_name])) {
      $func = $values[$field_name];

      return $func($bug);
    }
    else {
      return FALSE;
    }
  }

  function bug_deleted($event, $bug_id) {
    $bug = bug_get($bug_id);

    // We will only notification if someone other than handler is deleting this.
    $this->skip = $this->is_user_handler_same($bug);

    $this->skip = $this->skip || gpc_get_bool('slack_skip');

    $project = project_get_name($bug->project_id);
    $reporter = '@' . user_get_name(auth_get_current_user_id());
    $reporter = $this->get_user_alias($reporter);
    $summary = $this->clean_summary($bug);

    $msg = sprintf(plugin_lang_get('bug_deleted'), $reporter, $summary, $handler);
    $this->notify($msg, $this->get_webhook($project), $this->get_channel($project), $this->get_attachment($bug));
  }

  function is_user_handler_same($bug) {
    return auth_get_current_user_id() == $bug->handler_id;
  }

  function bugnote_add_edit($event, $bug_id, $bugnote_id) {
    $this->skip = $this->skip || gpc_get_bool('slack_skip');

    $bug = bug_get($bug_id);
    $url = string_get_bugnote_view_url_with_fqdn($bug_id, $bugnote_id);
    $project = project_get_name($bug->project_id);
    $summary = $this->clean_summary($bug);
    $reporter = '@' . user_get_name(auth_get_current_user_id());
    $reporter = $this->get_user_alias($reporter);

    $note = bugnote_get_text($bugnote_id);
    $msg = sprintf(plugin_lang_get($event === 'EVENT_BUGNOTE_ADD' ? 'bugnote_created' : 'bugnote_updated'),
      $reporter, $url, $summary, $note
    );
    $this->notify($msg, $this->get_webhook($project), $this->get_channel($project), $this->get_attachment($bug));
  }

  function bugnote_deleted($event, $bug_id, $bugnote_id) {
    $this->skip = $this->skip || gpc_get_bool('slack_skip');

    $bug = bug_get($bug_id);
    $project = project_get_name($bug->project_id);
    $url = string_get_bug_view_url_with_fqdn($bug_id);
    $summary = $this->clean_summary($bug);
    $reporter = '@' . user_get_name(auth_get_current_user_id());
    $reporter = $this->get_user_alias($reporter);
    $msg = sprintf(plugin_lang_get('bugnote_deleted'), $reporter, $url, $summary);
    $this->notify($msg, $this->get_webhook($project), $this->get_channel($project), $this->get_attachment($bug));
  }
}
