<?php
use Goutte\Client;

class Scraper
{
    public $debug = false;
    public $pageUrl = 'http://filter.phillyelectionresults.com/comprehensive.aspx';
    public $client;
    public $crawler;
    public $page;
    public $divisionNav = array('label' => 'divisions', 'select' => 'cboPrecincts', 'submit_button_id' => '#btnNext', 'submit_button_value' => 'View Division Results');
    public $wardNav = array('label' => 'wards', 'select' => 'cboGeography', 'submit_button_id' => '#btnNext', 'submit_button_value' => 'View Results >>');

    public function __construct(&$client)
    {
        $this->client = &$client;
        $this->goHome();
    }

    public function go($url, $method = 'GET')
    {
        $this->crawler = $this->client->request($method, $url);
    }

    public function goHome()
    {
        $this->go($this->pageUrl);
    }

    public function getForm($nav)
    {
        return $this->crawler->selectButton($nav['submit_button_value'])->form();
    }
    public function getPage($nav, $value)
    {
        $this->page = $this->client->submit($this->getForm($nav), array($nav['select'] => $value));

        return $this->page;
    }

    public function getNavData($nav, &$crawler)
    {

        // read the id/value pairs for the
        return $crawler->filter('#' . $nav['select'] . ' option')->each(function ($node) {
            if ($node) {
                return array('id' => $node->attr('value'), 'value' => $node->text());
            }
        });
    }

    public function getResults(&$page, &$indexes)
    {
        $results = $result = $temp = [];
        $resultType = '';
        $results['results'] = [];

        // grab everything and drop it in one container
        $rows = $page->filter('form h2, form h3, form h4, form table.results')->each(function ($node) {
            return array('nodeName' => $node->nodeName(), 'nodeText' => $node->text(), 'node' => $node);
        });

        // i'd do this in the each above, but I need to share values among nodes,
        // and these nodes are proximally, not hierarchically, arranged in the page.
        foreach ($rows as $row) {
            switch ($row['nodeName']) {
                case 'h2':
                    $resultType = trim($row['nodeText']);
                    if (count($result)) {
                        array_push($results['results'], array('party' => $partyId, 'progress' => $progress, 'race' => $raceId, 'desc' => $descId, 'candidates' => $result));
                        array_push($indexes['races'][$raceId], array('party' => $partyId, 'prog' => $progress, 'desc' => $descId));
                    }

                    // re-initialize
                    $result = [];
                    break;

                case 'h3':

                    // write extant result
                    if (count($result)) {
                        array_push($results['results'], array('party' => $partyId, 'progress' => $progress, 'race' => $raceId, 'desc' => $descId, 'candidates' => $result));
                        array_push($indexes['races'][$raceId], array('party' => $partyId, 'prog' => $progress, 'desc' => $descId));
                    }

                    // re-initialize
                    $result = [];

                    $temp = explode(':', trim($row['nodeText']));
                    $race = str_replace('*', '', trim($temp[1]));
                    if (!in_array($race, $indexes['races'])) {
                        array_push($indexes['races'], $race);
                    }
                    $raceId = array_search($race, $indexes['races']);
                    $temp = explode('-', $race);
                    $party = '';

                    // the assumptions here could bite me in the ass
                    if ($resultType !== 'Question Results' && count($temp) > 1) {
                        $party = trim($temp[count($temp) - 1]) === 'R' ? 'REPUBLICAN' : (trim($temp[count($temp) - 1]) === 'D' ? 'DEMOCRATIC' : '');
                    }
                    if (!in_array($party, $indexes['parties'])) {
                        array_push($indexes['parties'], $party);
                    }
                    $partyId = array_search($party, $indexes['parties']);
                    break;

                case 'h4':
                    $temp = explode("%", $row['nodeText']);
                    $progress = "0";
                    $desc = count($temp) === 1 ? $temp[0] : $temp;
                    if (count($temp) === 2) {
                        $progress = trim($temp[0]);
                        $desc = trim($temp[1]);
                    }
                    if (!in_array($desc, $indexes['descs'])) {
                        array_push($indexes['descs'], $desc);
                    }
                    $descId = array_search($desc, $indexes['descs']);
                    break;

                case 'table':

                    // pull out the result block
                    $temp = $row['node']->filter('tr')->each(function ($node) {
                        return $node->filter('td')->each(function ($node) {
                            return $node->text();
                        });
                    });

                    // drop row 0 (columns: we won't use)
                    array_shift($temp);

                    array_walk($temp, function ($values) use (&$result, &$indexes, $raceId) {
                        $name = str_replace('*', '', trim($values[0]));
                        if (!in_array($name, $indexes['candidates'])) {
                            array_push($indexes['candidates'], $name);
                        }
                        $nameId = array_search($name, $indexes['candidates']);

                        // percentage is always last element
                        $percentage = explode(' ', $values[count($values) - 1]);
                        if (!isset($indexes['votes'][$indexes['current']])) {
                            $indexes['votes'][$indexes['current']] = [];
                        }

                        if (!isset($indexes['votes'][$indexes['current']][$raceId])) {
                            $indexes['votes'][$indexes['current']][$raceId] = [];
                        }

                        if (!isset($indexes['votes'][$indexes['current']][$raceId][$nameId])) {
                            $indexes['votes'][$indexes['current']][$raceId][$nameId] = [];
                        }

                        switch (count($values)) {
                            case 4:
                                $party = trim($values[1]);
                                if (!in_array($party, $indexes['parties'])) {
                                    array_push($indexes['parties'], $party);
                                }
                                $partyId = array_search($party, $indexes['parties']);
                                array_push($result, array('name' => $nameId, 'party' => $partyId, 'percentage' => (float) trim($percentage[0]), 'votes' => (int) trim($values[2])));
                                array_push($indexes['votes'][$indexes['current']][$raceId][$nameId], array('party' => $partyId, 'perc' => (float) trim($percentage[0]), 'votes' => (int) trim($values[2])));
                                break;

                            case 3:
                                $partyId = array_search("", $indexes['parties']);
                                array_push($result, array('name' => $nameId, 'party' => $partyId, 'percentage' => (float) trim($percentage[0]), 'votes' => (int) trim($values[1])));
                                array_push($indexes['votes'][$indexes['current']][$raceId][$nameId], array('party' => $partyId, 'perc' => (float) trim($percentage[0]), 'votes' => (int) trim($values[1])));

                                break;

                            default:
                                $this->logthis('unexpected content: ' . json_encode($values), 'ERROR');

                                // don't use this set
                                return;
                                break;
                        }
                        unset($party, $percentage, $values);
                    });

                    break;
            }
        }
        if (count($result)) {
            array_push($results['results'], array('party' => $partyId, 'progress' => $progress, 'race' => $raceId, 'desc' => $descId, 'candidates' => $result));
        }

        unset($rows, $result);
        return $results;
    }

    public function logthis($message, $status = "OK")
    {
        file_put_contents('log', date(DATE_ATOM) . "\t" . $status . "\t" . $message . "\n", FILE_APPEND);
    }

    public function isUnlocked()
    {
        $check = json_decode(file_get_contents('status.json'));
        return $check->status === "unlocked";
    }

    public function unlock($status = "unlocked")
    {
        $this->setStatus($status);
    }

    public function lock($status = "locked")
    {
        $this->setStatus($status);
    }

    public function setStatus($status)
    {
        file_put_contents('status.json', json_encode(array('status' => $status, 'time' => date(DATE_ATOM))));
    }

    public function save(&$results)
    {
        file_put_contents('results.json', json_encode($results));
    }

    public function wwSave(&$results)
    {
        file_put_contents('../public_html/whowon/results.json', json_encode($results));
    }

    public function push()
    {
        $config = json_decode(file_get_contents('settings.json'));
        if ($config->server) {
            $sftp = new Net_SFTP($config->server);
            if (!$sftp->login($config->user, $config->pass)) {
                throw new Exception('SFTP Login Failed');
            } else {
                if (!$sftp->put($config->path . "/" . 'results.json', 'results.json', NET_SFTP_LOCAL_FILE)) {
                    throw new Exception('SFTP Transfer Failed');
                } else {
                    $this->logthis("successful transfer to " . $config->server);
                }
            }
        } else if ($config->path) {
            $this->logthis("saving to loal web path " . $config->path, 'OK');
            rename('results.json', $config->path . "/" . 'results.json');
        } else {
            $this->logthis("configuration (settings.json) missing a server and path", 'ERROR');
        }
    }
}