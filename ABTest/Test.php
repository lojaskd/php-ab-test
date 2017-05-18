<?php

namespace LojasKD\ABTest;

class Test
{
    private $variations = array();
    private $currentVariation = '!unset';
    private $currentVariationKey;
    private $testName;
    private $testRun = false;
    private $trialMode = false;
    private $content;
    private $tag = 'ab-test';
    private $testDomain;
    private $detectBots = true;
    private $isBot = false;

    public function __construct($trial = false)
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

        ob_start(array($this, 'execute', PHP_OUTPUT_HANDLER_REMOVABLE));

        $this->testDomain = '.' . filter_input(INPUT_SERVER, 'HTTP_HOST');
    }

    public function __destruct()
    {
        ob_end_flush();
    }

    public function setName($name)
    {
        $name = trim(strtolower($name));
        $name = preg_replace('/[^a-z0-9 _]*/', '', $name);
        $name = str_replace(' ', '_', $name);
        $this->testName = $name;
    }

    public function setDomain($domain)
    {
        $this->testDomain = !empty($domain) ? $domain : '.' . $_SERVER['HTTP_HOST'];
    }

    public function setTag($tag)
    {
        $this->tag = $tag;
    }

    public function addVariation($name, $value = '')
    {
        $name = trim(strtolower($name));
        $name = preg_replace('/[^a-z0-9 -]*/', '', $name);
        $name = str_replace(' ', '-', $name);

        array_push($this->variations, array('name' => $name, 'value' => $value));
    }

    public function execute($buffer)
    {
        $this->content = $buffer;

        if (!$this->testRun) {
            $this->runTest();
        }

        if (!$this->trialMode) {
            $this->recordUserSegment();
        }

        return $this->content;
    }

    public function runTest()
    {
        $this->getUserSegment();
        $this->grabContent();
        $this->testRun = true;
    }

    public function getUserSegment()
    {
        if ($this->currentVariation != '!unset' && $this->currentVariationKey != -1) {
            return $this->currentVariation;
        }

        if ($this->isBot == true) {
            $this->currentVariation = 'current';
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

        array_unshift($this->variations, array('name' => 'current', 'value' => ''));
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
