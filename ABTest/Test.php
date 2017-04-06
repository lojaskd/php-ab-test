<?php

namespace LojasKD\ABTest;

class Test
{
    private $variations = array();
    private $currentVariation = '!unset';
    private $currentVariationKey;
    private $testName;
    private $testRan = false;
    private $trialMode = false;
    private $content;
    private $tag = 'phpab';
    private $gaAuto = true;
    private $gaSlot = 1;
    private $testDomain;
    private $detectBots = true;
    private $isBot = false;
    private $version = '1.2';

    public function __construct($name, $trial = false)
    {
        if ($this->detectBots) {
            $httpUserAgent = filter_input(INPUT_SERVER, 'HTTP_USER_AGENT');
            $bots = array('googlebot', 'msnbot', 'slurp', 'ask jeeves', 'crawl', 'ia_archiver', 'lycos');
            foreach ($bots as $botName) {
                if (stripos($httpUserAgent, $botName) !== false) {
                    $this->trialMode = true;
                    $this->isBot = true;
                    break;
                }
            }
        }

        if ($this->isBot == false) {
            $this->trialMode = $trial;
        }

        ob_start(array($this, 'execute'));

        $this->testDomain = '.' . filter_input(INPUT_SERVER, 'HTTP_HOST');

        $name = trim(strtolower($name));
        $name = preg_replace('/[^a-z0-9 _]*/', '', $name);
        $name = str_replace(' ', '_', $name);
        $this->testName = $name;
    }

    public function __destruct()
    {
        ob_end_flush();
    }

    public function setDomain($domain)
    {
        $this->testDomain = !empty($domain) ? $domain : '.' . $_SERVER['HTTP_HOST'];
    }

    public function setGaSlot($slot)
    {
        $this->gaSlot = $slot;
    }

    public function setGaMode($mode)
    {
        $this->gaAuto = $mode;
    }

    public function setTag($tag)
    {
        $this->tag = $tag;
    }

    public function addVariation($name, $value = '')
    {
        $name = trim(strtolower($name));
        $name = preg_replace('/[^a-z0-9 _]*/', '', $name);
        $name = str_replace(' ', '_', $name);

        array_push($this->variations, array('name' => $name, 'value' => $value));
    }

    public function execute($buffer)
    {
        $this->content = $buffer;

        if (!$this->testRan) {
            $this->runTest();
        }

        if (!$this->trialMode) {
            $this->setUpGa();
        }

        $tmp = $this->content;
        $this->content = preg_replace(
            '/<body([^>]*?)class="([^"]*?)"([^>]*?)>/i',
            '<body${1}class="${2} ' . $this->tag . '-' . $this->currentVariation . '"${3}>',
            $this->content
        );

        if ($tmp == $this->content) {
            $this->content = preg_replace(
                '/<body([^>]*?)>/i',
                '<body${1} class="' . $this->tag . '-' . $this->currentVariation . '">',
                $this->content
            );
        }

        unset($tmp);

        $pos = strrpos($this->content, '</body>');
        if ($pos !== false) {
            $this->content = substr_replace(
                $this->content,
                '<!--A/B tests active with phpA/B ' . $this->version . '--></body>',
                $pos,
                strlen('</body>')
            );
        }

        $this->content = str_replace(
            '{' . $this->tag . ' ' . $this->testName . ' current_varation}',
            $this->currentVariation,
            $this->content
        );

        if (!$this->trialMode) {
            $this->recordUserSegment();
        }

        return $this->content;
    }

    public function runTest()
    {
        $this->getUserSegment();
        $this->grabContent();

        $open_tag = '{' . $this->tag . ' ' . $this->testName . '}';
        $close_tag = '{/' . $this->tag . ' ' . $this->testName . '}';
        $test_open = strpos($this->content, $open_tag);
        $test_close = strpos($this->content, $close_tag);

        while ($test_open !== false) {
            if ($this->currentVariation != 'control') {
                if ($test_close === false && $test_open !== false) {
                    $this->content = substr_replace(
                        $this->content,
                        $this->variations[$this->currentVariationKey]['value'],
                        $test_open,
                        strlen($open_tag)
                    );
                } elseif ($test_close !== false && $test_open !== false) {
                    $diff = $test_close + strlen($close_tag) - $test_open;
                    $this->content = substr_replace(
                        $this->content,
                        $this->variations[$this->currentVariationKey]['value'],
                        $test_open,
                        $diff
                    );
                }
            } else {
                $this->content = str_replace(
                    $open_tag,
                    $this->variations[$this->currentVariationKey]['value'],
                    $this->content
                );
                $this->content = str_replace($close_tag, '', $this->content);
            }

            $test_open = strpos($this->content, $open_tag, $test_open);
            $test_close = strpos($this->content, $close_tag, $test_open);
        }

        $this->testRan = true;
    }

    public function getUserSegment()
    {
        if ($this->currentVariation != '!unset' && $this->currentVariationKey != -1) {
            return $this->currentVariation;
        }

        if ($this->isBot == true) {
            $this->currentVariation = 'control';
            return $this->currentVariation;
        }

        if (get_magic_quotes_gpc() == true) {
            $_COOKIE[$this->tag . '-' . $this->testName] = stripslashes($_COOKIE[$this->tag . '-' . $this->testName]);
        }

        if ($this->trialMode == false) {
            $key = $this->tag . '-' . $this->testName;
            if (array_key_exists($key, $_COOKIE)) {
                $this->currentVariation = $_COOKIE[$key];
            }

            if (empty($this->currentVariation)) {
                $this->currentVariation = '!unset';
            }
        } else {
            $this->currentVariation = '!unset';
        }

        array_unshift($this->variations, array('name' => 'control', 'value' => ''));

        $valid = false;

        $this->currentVariationKey = 0;
        foreach ($this->variations as $n => $v) {
            if ($v['name'] == $this->currentVariation) {
                $valid = true;
                break;
            }
            $this->currentVariationKey++;
        }

        if ($this->currentVariation == '!unset' || !$valid) {
            srand((double)microtime() * 1000003);
            $this->currentVariationKey = array_rand($this->variations);
            $this->currentVariation = $this->variations[$this->currentVariationKey]['name'];
        }

        return $this->currentVariation;
    }

    private function grabContent()
    {
        if (empty($this->content)) {
            $this->content = ob_get_contents();
        }
    }

    private function setUpGa()
    {
        $try_auto = false;
        $sync = '{' . $this->tag . ' ' . $this->testName . ' ga_sync}';
        $async = '{' . $this->tag . ' ' . $this->testName . ' ga_async}';
        $syncPos = strpos($this->content, $sync);
        if ($syncPos !== false) {
            $this->content = str_replace(
                $sync,
                "pageTracker._setCustomVar({$this->gaSlot}, '{$this->testName}', '{$this->currentVariation}', 3);",
                $this->content
            );
        } else {
            $asyncPos = strpos($this->content, $async);
            if ($asyncPos !== false) {
                $this->content = str_replace(
                    $async,
                    "ga('set', 'dimension{$this->gaSlot}', '{$this->testName}', '{$this->currentVariation}');",
                    $this->content
                );
            } else {
                $try_auto = true;
            }
        }

        if ($this->gaAuto == true && $try_auto == true) {
            $sync = strpos($this->content, 'pageTracker._trackPageview');
            if ($sync === false) {
                $async = preg_match(
                    '/ga\(\'send\', \[[\'\"]_trackPageview[\'\"]\]\)/',
                    $this->content,
                    $matches,
                    PREG_OFFSET_CAPTURE
                );
                if ($async == false) {
                    $auto_fail = true;
                    $async = false;
                } else {
                    $auto_fail = false;
                    $async = $matches[0][1];
                }
            } else {
                $auto_fail = false;
            }

            if ($auto_fail === false && $sync !== false) {
                $this->content = substr($this->content, 0, $sync - 1) .
                    "pageTracker._setCustomVar({$this->gaSlot}, '{$this->testName}', '{$this->currentVariation}', 3);" .
                    substr($this->content, $sync);
            } elseif ($auto_fail === false && $async !== false) {
                $this->content = substr($this->content, 0, $async - 1) .
                    "ga('set', 'dimension{$this->gaSlot}', '{$this->testName}', '{$this->currentVariation}');" .
                    substr($this->content, $async);
            }
        }
    }

    private function recordUserSegment()
    {
        $cookie_domain = (
        $colon_position = strrpos($this->testDomain, ":")) === false ?
            $this->testDomain :
            substr($this->testDomain, 0, $colon_position);

        setcookie(
            $this->tag . '-' . $this->testName,
            $this->currentVariation,
            time() + (60 * 60 * 24 * 365),
            '/',
            $cookie_domain
        );
    }
}
