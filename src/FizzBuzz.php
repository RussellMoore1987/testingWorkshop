<?php

namespace App;

class FizzBuzz
{
    public function run(int $number): string
    {
        if ($number % 15 === 0) {
            return 'FizzBuzz';
        }

        if ($number % 3 === 0) {
            return 'Fizz';
        }

        if ($number % 5 === 0) {
            return 'Buzz';
        }

        return (string) $number;
    }

    public function runRang(int $start, int $end): array
    {
        $result = [];

        foreach (range($start, $end) as $number) {
            $result[] = $this->run($number);
        }

        return $result;
    }
}