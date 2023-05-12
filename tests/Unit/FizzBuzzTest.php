<?php
    
namespace Tests\Unit;

use App\FizzBuzz;
use PHPUnit\Framework\TestCase;

class FizzBuzzTest extends TestCase
{
    private $fizzBuzz;
    
    public function setUp(): void
    {
        parent::setUp();

        $this->fizzBuzz = new FizzBuzz();
    }

    public function test_FizzBuzz_gets_value_for_single_response(): void
    {
        $this->assertEquals('1', $this->fizzBuzz->run(1));
        $this->assertEquals('Fizz', $this->fizzBuzz->run(3));
        $this->assertEquals('Buzz', $this->fizzBuzz->run(5));
        $this->assertEquals('FizzBuzz', $this->fizzBuzz->run(15));
    }

    public function test_FizzBuzz_gets_value_for_a_given_rang(): void
    {
        $result = [
            '1',
            '2',
            'Fizz',
            '4',
            'Buzz',
            'Fizz',
            '7',
            '8',
            'Fizz',
            'Buzz',
            '11',
            'Fizz',
            '13',
            '14',
            'FizzBuzz',
        ];

        $this->assertEquals($result, $this->fizzBuzz->runRang(1, 15));
    }
}