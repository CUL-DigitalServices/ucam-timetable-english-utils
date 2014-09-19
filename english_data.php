<?php

function pre($to_print) {
    echo '<pre>';
    print_r($to_print);
    echo '</pre>';
}

function connect_to_db() {
    $db = mysqli_connect('127.0.0.1', 'root', '', 'tt_english_data');
    if ($db->connect_errno > 0) {
        die('Unable to connect to database [' . $db->connect_error . ']');
    }
    return $db;
}

function close_db($db) {
    $db->close();
}

function get_start_end_for_year($year) {
    return array(
        'start' => strtotime($year . '-10-01'),
        'end' => strtotime($year + 1 . '-07-01')
    );
}

function get_events_for_year($db, $year) {
    $events = array();
    $startEnd = get_start_end_for_year($year);
    $statement = $db->prepare(
        'SELECT mrbs_entry.*, mrbs_room.room_name FROM mrbs_entry
         LEFT JOIN (mrbs_room) ON (mrbs_room.id = mrbs_entry.room_id)
         WHERE start_time >= ?
         AND end_time <= ?
         ORDER BY mrbs_entry.start_time ASC'
    );
    if ($statement == false) {
        die('prepare() failed: ' . htmlspecialchars($db->error));
    }
    $statement->bind_param('ii', $startEnd['start'], $startEnd['end']);
    $statement->execute();
    $parameters = array();

    $meta = $statement->result_metadata();

    while ($field = $meta->fetch_field()) {
        $parameters[] = &$row[$field->name];
    }

    call_user_func_array(array($statement, 'bind_result'), $parameters);

    while ($statement->fetch()) {
        foreach($row as $key => $val) {
            $x[$key] = $val;
        }
        $events[] = $x;
    }

    return $events;
}

function build_timetables_hierarchy($events) {
    $parts = array(
        'prelim_primary' => array(),
        'part1_primary' => array(),
        'part2_primary' => array(),
        'grad_primary' => array(),
        'english_research_seminars' => array()
    );

    foreach ($events as $event) {
        foreach ($parts as $partKey => &$partVal) {
            $moduleTitleForPart = $event[$partKey];
            if (!empty($moduleTitleForPart)) {
                if ($partKey == 'grad_primary') {
                    if ($moduleTitleForPart == 'research-seminar') {
                        $parts['english_research_seminars'][substitute_module($moduleTitleForPart)][$event['name']][] = $event;
                    } else {
                        $partVal[substitute_module($moduleTitleForPart)][$event['name']][] = $event;
                    }
                } else if ($moduleTitleForPart == '7ab') {
                    $partVal['Paper 7a'][$event['name']][] = $event;
                    $partVal['Paper 7b'][$event['name']][] = $event;
                } else {
                    if ($partKey == 'part1_primary' || $partKey == 'part2_primary' || $partKey == 'prelim_primary') {
                         if (strcasecmp($moduleTitleForPart, "general") == 0) {
                            $moduleTitleForPart = "General";
                        } else {
                            $moduleTitleForPart = 'Paper ' . $moduleTitleForPart;
                        }
                    }
                    $partVal[$moduleTitleForPart][$event['name']][] = $event;
                }
            }
        }
    }

    return array(
        'english_tripos' => array(
            'prelim' => $parts['prelim_primary'],
            'I' => $parts['part1_primary'],
            'II' => $parts['part2_primary']
        ),
        'english_graduates' => array(
            'graduates' => $parts['grad_primary']
        ),
        'english_research_seminars' => array(
            'research_seminars' => $parts['english_research_seminars']
        )
    );
}

function get_event_type_from_name($name) {
    $types = array (
        'L' => 'lecture',
        'C' => 'class',
        'S' => 'seminar'
    );
    if (preg_match("/\(\d+([a-z]|[A-Z])/", $name, $matches) && isset($types[$matches[1]])) {
        return $types[$matches[1]];
    }
    return NULL;
}

function substitute_module($moduleName) {
    $substitutions = array(
        'american-mphil' => 'American Literature MPhil',
        'c-and-c-mphil' => 'Criticism and Culture MPhil',
        'eighteenth-mphil' => '18th Century and Romantic Studies MPhil',
        'modern-mphil' => 'Modern and Contemporary MPhil',
        'med-ren-mphil' => 'Medieval and Renaissance Literature MPhil',
        'research-seminar' => 'Research Seminar',
        'phd' => 'PhD',
        'general' => 'General'
    );
    $substitution = $substitutions[$moduleName];
    return $substitution ? $substitution : $moduleName;
}

function build_timetables_xml($timetables) {
    $root = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><moduleList/>');
    foreach ($timetables as $timetableKey => $timetableVal) {
        foreach ($timetableVal as $partKey => $partVal) {
            foreach ($partVal as $moduleKey => $moduleVal) {
                $moduleXML = $root->addChild('module');
                $modulePath = $moduleXML->addChild('path');
                $modulePath->addChild('tripos', htmlspecialchars($timetableKey));
                $modulePath->addChild('part', htmlspecialchars($partKey));
                $moduleXML->addChild('name', htmlspecialchars($moduleKey));
                foreach ($moduleVal as $seriesKey => $seriesVal) {
                    $series = $moduleXML->addChild('series');
                    $series->addChild('uniqueid', md5($timetableKey . $partKey . $moduleKey . $seriesKey));
                    $series->addChild('name', htmlspecialchars($seriesKey));
                    foreach ($seriesVal as $eventVal) {
                        $event = $series->addChild('event');
                        $event->addChild('uniqueid', htmlspecialchars($eventVal['id']));
                        $event->addChild('name', htmlspecialchars($eventVal['name']));
                        $event->addChild('location', get_event_location($eventVal));
                        $event->addChild('lecturer', htmlspecialchars($eventVal['requestor']));
                        $event->addChild('date', date('Y-m-d', $eventVal['start_time']));
                        $event->addChild('start', date('H:i:s', $eventVal['start_time']));
                        $event->addChild('end', date('H:i:s', $eventVal['end_time']));
                        $event->addChild('type', get_event_type_from_name($eventVal['name']));
                    }
                }
            }
        }
    }
    return $root;
}

function get_event_location($event) {
    $location = !empty($event['other_room_name']) ? $event['other_room_name'] : $event['room_name'];
    if (preg_match('/^slot/i', $location)) {
        $location = 'TBA';
    }
    return htmlspecialchars($location);
}

function init() {
    date_default_timezone_set('Europe/London');

    $db = connect_to_db();

    // Get the year from the query parameters (default to 2014)
    $year = isset($_GET['year']) ? $_GET['year'] : 2014;
    // Get the events for the requested year
    $events = get_events_for_year($db, $year);
    // Build the timetable hierarchy based on the events
    $timetables = build_timetables_hierarchy($events);
    // Generate the importable XML from the timetable hierarchy
    $xml = build_timetables_xml($timetables);

    close_db($db);
    Header('Content-type: text/xml');
    print($xml->asXML());
}

init();

?>
