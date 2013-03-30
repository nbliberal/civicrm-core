<?php
/*
 +--------------------------------------------------------------------+
 | CiviCRM version 4.3                                                |
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC (c) 2004-2013                                |
 +--------------------------------------------------------------------+
 | This file is a part of CiviCRM.                                    |
 |                                                                    |
 | CiviCRM is free software; you can copy, modify, and distribute it  |
 | under the terms of the GNU Affero General Public License           |
 | Version 3, 19 November 2007 and the CiviCRM Licensing Exception.   |
 |                                                                    |
 | CiviCRM is distributed in the hope that it will be useful, but     |
 | WITHOUT ANY WARRANTY; without even the implied warranty of         |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.               |
 | See the GNU Affero General Public License for more details.        |
 |                                                                    |
 | You should have received a copy of the GNU Affero General Public   |
 | License and the CiviCRM Licensing Exception along                  |
 | with this program; if not, contact CiviCRM LLC                     |
 | at info[AT]civicrm[DOT]org. If you have questions about the        |
 | GNU Affero General Public License or the licensing of CiviCRM,     |
 | see the CiviCRM license FAQ at http://civicrm.org/licensing        |
 +--------------------------------------------------------------------+
*/

/**
 * Manage the download, validation, and rendering of community messages
 */
class CRM_Core_CommunityMessages {

  const DEFAULT_MESSAGES_URL = 'http://alert.civicrm.org/alert?prot=1&ver={ver}&uf={uf}&sid={sid}';
  const DEFAULT_PERMISSION = 'administer CiviCRM';

  /**
   * Default time to wait before retrying
   */
  const DEFAULT_RETRY = 7200; // 2 hours

  /**
   * @var CRM_Utils_HttpClient
   */
  protected $client;

  /**
   * @var CRM_Utils_Cache_Interface
   */
  protected $cache;

  /**
   * @var FALSE|string
   */
  protected $messagesUrl;

  /**
   * @param CRM_Utils_Cache_Interface $cache
   * @param CRM_Utils_HttpClient $client
   */
  public function __construct($cache, $client, $messagesUrl = NULL) {
    $this->cache = $cache;
    $this->client = $client;
    if ($messagesUrl === NULL) {
      $this->messagesUrl = CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::SYSTEM_PREFERENCES_NAME, 'community_messages_url', NULL, self::DEFAULT_MESSAGES_URL);
    }
    else {
      $this->messagesUrl = $messagesUrl;
    }
  }

  /**
   * Get the messages document
   *
   * @return NULL|array
   */
  public function getDocument() {
    if ($this->messagesUrl === FALSE) {
      return NULL;
    }

    $isChanged = FALSE;
    $document = $this->cache->get('communityMessages');

    if (empty($document) || !is_array($document)) {
      $document = array(
        'messages' => array(),
        'expires' => 0, // ASAP
        'ttl' => self::DEFAULT_RETRY,
        'retry' => self::DEFAULT_RETRY,
      );
      $isChanged = TRUE;
    }

    if ($document['expires'] <= CRM_Utils_Time::getTimeRaw()) {
      $newDocument = $this->fetchDocument($this->messagesUrl);
      if ($newDocument && $this->validateDocument($newDocument)) {
        $document = $newDocument;
        $document['expires'] = CRM_Utils_Time::getTimeRaw() + $document['ttl'];
      }
      else {
        $document['expires'] = CRM_Utils_Time::getTimeRaw() + $document['retry'];
      }
      $isChanged = TRUE;
    }

    if ($isChanged) {
      $this->cache->set('communityMessages', $document);
    }

    return $document;
  }

  /**
   * Download document from URL and parse as JSON
   *
   * @param string $url
   * @return NULL|array parsed JSON
   */
  public function fetchDocument($url) {
    list($status, $json) = $this->client->get(CRM_Utils_System::evalUrl($url));
    if ($status != CRM_Utils_HttpClient::STATUS_OK || empty($json)) {
      return NULL;
    }
    $doc = json_decode($json, TRUE);
    if (empty($doc) || json_last_error() != JSON_ERROR_NONE) {
      return NULL;
    }
    return $doc;
  }

  /**
   * Pick one message
   *
   * @param callable $permChecker
   * @param array $components
   * @return NULL|array
   */
  public function pick() {
    $document = $this->getDocument();
    $messages = array();
    foreach ($document['messages'] as $message) {
      if (!isset($message['perms'])) {
        $message['perms'] = array(self::DEFAULT_PERMISSION);
      }
      if (!CRM_Core_Permission::checkAnyPerm($message['perms'])) {
        continue;
      }

      if (isset($message['components'])) {
        $enabled = array_keys(CRM_Core_Component::getEnabledComponents());
        if (count(array_intersect($enabled, $message['components'])) == 0) {
          continue;
        }
      }

      $messages[] = $message;
    }
    if (empty($messages)) {
      return NULL;
    }

    $idx = rand(0, count($messages) - 1);
    return $messages[$idx];
  }

  /**
   * @param string $markup
   * @return string
   */
  public static function evalMarkup($markup) {
    throw new Exception('not implemented');
  }

  /**
   * Ensure that a document is well-formed
   *
   * @param array $document
   * @return bool
   */
  public function validateDocument($document) {
    if (!isset($document['ttl']) || !is_integer($document['ttl'])) {
      return FALSE;
    }
    if (!isset($document['retry']) || !is_integer($document['retry'])) {
      return FALSE;
    }
    if (!isset($document['messages']) || !is_array($document['messages'])) {
      return FALSE;
    }
    foreach ($document['messages'] as $message) {
      // TODO validate $message['markup']
    }

    return TRUE;
  }

}
