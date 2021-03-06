<?php
if (! defined('BASEPATH'))
    exit('No direct script access allowed');

require_once (APPPATH . 'libraries/JSONImportStateMachine/JSONImportStateInterface.php');

/**
 * JSON Import state inside marks array.
 * Every line from now on should contain saved mark object
 * JSON representation with dependencies.
 * @author kip9
 *
 */
class JSONImportStateMarks implements JSONImportStateInterface
{

    /**
     * Flag deciding if we want to return import details
     * @var boolean
     */
    const RETURN_DETAILS = true;

    /**
     * Flag deciding if we want to return warning and error
     * entries for details.
     * If RETURN_DETAILS is set to false, this flag is not
     * taken into account
     * @var boolean
     */
    const RETURN_DETAILS_ERRORS_ONLY = true;
    
    /*
     * Result keys
     */
    /**
     * Number of marks imported
     * @var string
     */
    const RESULT_ADDED = 'added';
    
    /**
     * Number of marks already existing in the system
     * @var string
     */
    const RESULT_SKIPPED = 'skipped';
    
    /**
     * Number of marks not imported due to errors
     * @var string
     */
    const RESULT_FAILED = 'failed';

    /**
     * Import status and data
     *
     * @var array
     */
    private $importData;

    /**
     * Cache for 'unlabeled' label id used to mark entries with unknown label
     * 
     * @var int
     */
    private $unlabeled_label_id = null;

    public function __construct($importData)
    {
        $this->importData = $importData;
        $this->importData['result'] = array(
            self::RESULT_ADDED => 0,
            self::RESULT_SKIPPED => 0,
            self::RESULT_FAILED => 0,
            'total' => 0
        );
        $this->CI = & get_instance();
    }

    /**
     * (non-PHPdoc)
     *
     * @see JSONImportStateInterface::processLine()
     */
    public function processLine($line)
    {
        // Finished
        if (mb_ereg_match("^[ ]*\][ ]*$", $line)) {
            return $this->importData;
        } else {
            // Strip trailing comma and simulate JSON
            $jsonLine = mb_ereg_replace("\\,[ ]*$", '', $line);
            $decodedMark = json_decode($jsonLine);
            $importResult = $this->importMark($decodedMark);
            $this->importData['result']['total'] ++;
            $this->importData['result'][$importResult['result']] ++;
            if (self::RETURN_DETAILS && (! self::RETURN_DETAILS_ERRORS_ONLY || $importResult['result'] === self::RESULT_FAILED || ! empty($importResult['warnings']))) {
                $this->importData['details'][$decodedMark->mark_id] = $importResult;
            }
            return $this;
        }
    }

    /**
     * Import mark object into system
     *
     * @param stdObj $markObject
     *            Mark data imported from file
     * @return array Result array
     */
    private function importMark($markObject)
    {
        $result = array();
        $this->CI->load->helper('data_helper');
        // Run in transaction
        $this->CI->db->trans_start();
        if ($this->importData['meta']['export_version'] == 1) {
            $this->CI->load->model('marks_model', 'mark');
            $markArray = array(
                'created_on' => $markObject->created_on,
                'title' => (empty($markObject->title) ? 'No title' : $markObject->title),
                'url' => $markObject->url,
                'embed' => $markObject->embed
            );
            // Import mark object
            $mark = $this->CI->mark->import($markArray);
            // Succesfully created mark
            if ($mark !== false && isset($mark->mark_id)) {
                // Try to create user_mark and other related records
                $this->CI->load->model('users_to_marks_model', 'user_marks');
                $user_mark = $this->CI->user_marks->readComplete("users_to_marks.user_id = '" . $this->importData['user_id'] . "' AND users_to_marks.mark_id = '" . $mark->mark_id . "' AND users_to_marks.active = '1'");
                // User mark does not exist - add one
                if (! isset($user_mark->mark_id)) {
                    // Set default options
                    $options = array(
                        'user_id' => $this->importData['user_id'],
                        'mark_id' => $mark->mark_id,
                        'active' => $markObject->active,
                        'archived_on' => $markObject->archived_on,
                        'created_on' => $markObject->created_on,
                        
                    );
                    
                    // Label ID (not required)
                    if (isset($markObject->label_id) && is_numeric($markObject->label_id)) {
                        $this->CI->load->model('labels_model', 'labels');
                        $label = $this->CI->labels->readComplete("(labels.user_id IS NULL OR labels.user_id='" . $this->importData['user_id'] . "') AND labels.active='1' AND labels.name = " . $this->CI->db->escape($markObject->label_name), 1);
                        if (! empty($label) && isset($label->label_id)) {
                            $options['label_id'] = $label->label_id;
                        } else {
                            if (! empty($this->unlabeled_label_id)) {
                                $options['label_id'] = $this->unlabeled_label_id;
                                $result['warnings'][] = 'Label ' . $markObject->label_name . ' not found. Marked as Unlabeled.';
                            } else 
                                if ($this->unlabeled_label_id === false) {
                                    $result['warnings'][] = 'Label ' . $markObject->label_name . ' not found. Stripped label info.';
                                } else {
                                    // Label not found and no unlabeled cache - looking for unlabeled label id
                                    $label = $this->CI->labels->readComplete("(labels.user_id IS NULL OR labels.user_id='" . $this->importData['user_id'] . "') AND labels.active='1' AND labels.name = " . $this->CI->db->escape('Unlabeled'), 1);
                                    if (! empty($label) && isset($label->label_id)) {
                                        $options['label_id'] = $label->label_id;
                                        // Cache the id of unlabeled label id
                                        $this->unlabeled_label_id = $label->label_id;
                                        $result['warnings'][] = 'Label ' . $markObject->label_name . ' not found. Marked as Unlabeled.';
                                    } else {
                                        // There is no unlabeled label - cache invalid value to mark
                                        $this->unlabeled_label_id = false;
                                        $result['warnings'][] = 'Label ' . $markObject->label_name . ' not found. Stripped label info.';
                                    }
                                }
                        }
                    }
                    
                    // Notes (not required)
                    if (isset($markObject->notes) && ! empty($markObject->notes)) {
                        $options['notes'] = $markObject->notes;
                        $tags = getTagsFromHash($options['notes']);
                    }
                    
                    // Figure if any automatic labels should be applied
                    $smart_info = getSmartLabelInfo($markObject->url);
                    if (isset($smart_info['key']) && ! empty($smart_info['key']) && ! isset($options['label_id'])) {
                        
                        // Load labels model
                        // Sort by user_id DESC (if user has same rule as system, use the user's rule)
                        // Try to extract label
                        $this->CI->load->model('labels_model', 'labels');
                        $this->CI->labels->sort = 'user_id DESC';
                        $label = $this->labels->readComplete("(labels.user_id IS NULL OR labels.user_id = '" . $this->importData['user_id'] . "') AND labels.smart_key = '" . $smart_info['key'] . "' AND labels.active = '1'", 1);
                        
                        // If a label id is found
                        // Set it to options to save
                        if (isset($label->settings->label->id)) {
                            $options['label_id'] = $label->settings->label->id;
                        }
                    }
                    
                    // Create the mark
                    $user_mark = $this->CI->user_marks->import($options);
                    $result['result'] = 'added';
                } else{
                    $result['result'] = 'skipped';
                }
                // Added user mark
                if (isset($user_mark->mark_id)) {
                    // If tags are present, add them
                    // Get updated result
                    if (isset($tags)) {
                        self::addTags($tags, $user_mark->mark_id);
                    }
                }
            } else 
                if ($mark !== false) {
                    foreach ($mark as $errorCode => $errorMessage) {
                        $result['errors'][] = array(
                            'error_code' => $errorCode,
                            'error_message' => $errorMessage
                        );
                    }
                } else{
                    $result['errors'][] = formatErrors(500);
                }
        } else {
            $result['errors'][] = array(
                'error_message' => 'Invalid data format ' . $this->importData['meta']['export_version']
            );
        }
        $this->CI->db->trans_complete();
        // Check if DB operations succeeded
        if ($this->CI->db->trans_status() === FALSE) {
            // Internal error
            $result['errors'][] = formatErrors(500);
        }
        if (! empty($result['errors'])) {
            $result['result'] = 'failed';
        }
        return $result;
    }

    /**
     * Add tags for specified mark
     *
     * @param array $tags            
     * @param int $mark_id            
     */
    private function addTags($tags, $mark_id)
    {
        if (! empty($tags) && is_array($tags)) {
            // Update users_to_marks record
            $this->CI->load->model('tags_model', 'tag');
            $this->CI->load->model('user_marks_to_tags_model', 'mark_to_tag');
            
            $tag_ids = array();
            foreach ($tags as $k => $tag) {
                $tag_name = trim($tag);
                $slug = generateSlug($tag);
                
                if (! empty($slug)) {
                    $tag = $this->CI->tag->read("slug = '" . $slug . "'", 1, 1, 'tag_id');
                    if (! isset($tag->tag_id)) {
                        $tag = $this->CI->tag->create(array(
                            'name' => $tag_name,
                            'slug' => $slug
                        ));
                    }
                    
                    // Add tag to mark
                    if (isset($tag->tag_id)) {
                        $res = $this->CI->mark_to_tag->create(array(
                            'users_to_mark_id' => $mark_id,
                            'tag_id' => $tag->tag_id,
                            'user_id' => $this->importData['user_id']
                        ));
                    }
                    
                    // Save all tag ids
                    if (isset($res->tag_id)) {
                        array_push($tag_ids, $res->tag_id);
                    }
                }
            }
            
            // Delete old tags
            $delete_where = (! empty($tag_ids)) ? " AND tag_id <> '" . implode("' AND tag_id <> '", $tag_ids) . "'" : '';
            $delete = $this->CI->mark_to_tag->delete("users_to_mark_id = '" . $mark_id . "' AND user_id = '" . $this->importData['user_id'] . "'" . $delete_where);
        }
    }
}