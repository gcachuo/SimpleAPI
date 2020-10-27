<?php

class Stopwatch
{
    private $lap_start = 0;
    private $begin = 0;
    public $measure_points = array();
    private static $_Instance;
    private $total;

    public function start()
    {
        $this->begin = microtime(true);
        $this->lap_start = $this->begin;
    }

    public function lap_end($name)
    {
        $time = microtime(true) - $this->lap_start;
        $this->measure_points[$name] = $time;
        $this->lap_start = microtime(true);
    }

    public function end($name)
    {
        $time = microtime(true) - $this->lap_start;
        $this->measure_points[$name] = $time;

        $total = 0;
        foreach ($this->measure_points as $key => $data) {
            $total = $total + $data;
        }
        $this->total = $total;

        self::$_Instance[$name] = $this;
    }

    public static function report($name = null)
    {
        if (ENVIRONMENT !== 'cli') {
            return null;
        }
        if (!$name) {
            return self::$_Instance;
        }
        System::cli_echo(str_pad($name, 35), 'yellow');
        $_this = self::$_Instance[$name];
        foreach ($_this->measure_points as $key => $data) {
            $percent = $data / ($_this->total / 100);
            System::cli_echo(str_pad($key, 35) . ' : ' . number_format($data, 8) . ' (' . number_format($percent, 2) . '%)', 'blue');
        }

        System::cli_echo(str_pad('Total', 35) . ' : ' . number_format($_this->total, 8), 'green');
        return $_this->total;
    }
}
