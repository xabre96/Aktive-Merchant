<?php
/* vim: set expandtab tabstop=4 shiftwidth=4 softtabstop=4: */

use AktiveMerchant\Billing\Gateways\Psigate;
use AktiveMerchant\Billing\Base;
use AktiveMerchant\Billing\CreditCard;
use AktiveMerchant\Billing\Exception;

/**
 * Test file for PsiGate merchant
 *
 * @package Aktive-Merchant
 * @author  Scott Gifford <sgifford@suspectclass.com>
 * @license http://www.opensource.org/licenses/mit-license.php
 *
 */
require_once 'config.php';

class TestPsigate extends Psigate {
  protected static $URL = "https://dev.psigate.com:7989/Messenger/XMLMessenger";
}

class BadNetwork extends TestPsigate {
    // Bad host and port numbers to simulate network failure
    static $URL = "https://localhost:7777/Messenger/XMLMessenger";
}

class BadRequest extends TestPsigate {
    protected function post_data($money, $creditcard, $options) {
        return "<" . parent::post_data($money, $creditcard, $options);
    }
}

class BadResponse extends TestPsigate {
    protected function ssl_post($endpoint, $data, $options = array()) {
        // Prepend a character to make parsing fail
        return "<" . parent::ssl_post($endpoint, $data, $options);
    }
}


class PsigateTest extends AktiveMerchant\TestCase
{
    public $gateway;
    public $amount;
    public $options;
    public $creditcard;
    public $ordernum = 0;
    public $base_amount;
    public $ordername;
    private $login_info;

    /**
     * Setup
     */
    function setUp()
    {
        $this->login_info = $this->getFixtures()->offsetGet('psigate');
        
        $this->gateway = new TestPsigate($this->login_info);

        Base::mode('test');
        $this->gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH);

        $this->creditcard = new CreditCard(array(
            "first_name" => "John",
            "last_name" => "Doe",
            "number" => "4111111111111111",
            "month" => "01",
            "year" => "2015",
            "verification_value" => "000"
        )
    );

        $this->options = array(
            'description' => 'Psigate Test Transaction',
            'address' => array(
                'address1' => '1234 Street',
                'zip' => '98004',
                'state' => 'WA'
            )
        );

        $this->ordername = $this->gateway->generateUniqueId();
        $this->base_amount = rand(1,100);
    }

    private function next_order() {
        $this->ordernum++;
        $this->options['order_id'] = $this->ordername . '.' . $this->ordernum;
        $this->amount = $this->base_amount + ($this->ordernum / 100.0);
    }

    /**
     * Tests
     */

    public function testInitialization() 
    {
        
        $this->assertNotNull($this->gateway);
        
        $this->assertNotNull($this->creditcard);

        $this->assertImplementation(
            array(
                'Charge',
                'Credit',
            )
        );
    }

    public function testSuccessfulPurchase()
    {
        $this->next_order();
        $response = $this->gateway->purchase($this->amount, $this->creditcard, $this->options);
        $this->assert_success($response);
        $this->assertTrue($response->test());
        $this->assertEquals('Success', $response->message());
    }
    
    public function testSuccessfulAuthorization()
    {
        $this->next_order();
        $response = $this->gateway->authorize($this->amount, $this->creditcard, $this->options);
        $this->assert_success($response);
        $this->assertTrue($response->test());
        $this->assertEquals('Success', $response->message());
    }

    public function testAuthorizationAndCapture()
    {
        $this->next_order();
        $response = $this->gateway->authorize($this->amount, $this->creditcard, $this->options);
        $this->assert_success($response);

        $authorization = $response->authorization();

        $capture = $this->gateway->capture($this->amount, $authorization, $this->options);
        $this->assert_success($capture);
        $this->assertEquals('Success', $capture->message());
    }

    public function testVoid()
    {
        $this->next_order();
        $response = $this->gateway->purchase($this->amount, $this->creditcard, $this->options);
        $this->assert_success($response);

        $authorization = $response->authorization();

        $void = $this->gateway->void($authorization);
        $this->assert_success($void);
        $this->assertEquals('Success', $void->message());
    }

    public function testCredit()
    {
        $this->next_order();

        $response = $this->gateway->purchase($this->amount, $this->creditcard, $this->options);
        $this->assert_success($response);

        $authorization = $response->authorization();

        // A little less than half price, so there's a bit left at the end
        $half_price = ($this->amount / 2) - 0.02; 

        // Refund half of the credit
        $credit = $this->gateway->credit($half_price, $authorization);
        $this->assert_success($credit);
        $this->assertEquals('Success', $credit->message());

        // The other half
        $credit = $this->gateway->credit($half_price, $authorization);
        $this->assert_success($credit);
        $this->assertEquals('Success', $credit->message());

        // This one should fail
        $credit = $this->gateway->credit($half_price, $authorization);
        $this->assertFalse($credit->success());
        $this->assertEquals('PSI-2005:Credit exceeds remaining order value.', $credit->message());    
    }

    public function testFailure()
    {
        $this->next_order();      

        // Test decline
        try {
            $this->gateway->test_mode(Psigate::TEST_MODE_ALWAYS_DECLINE); // Always fail
            $response = $this->gateway->purchase($this->amount, $this->creditcard, $this->options);
            $this->assertFalse($response->success());
        } catch (Exception $ex) {
            // Reset test mode in case of failure, so further tests won't all fail
            $this->gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH); // Return to regular mode
            throw $ex;
        }
        $this->gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH); // Return to regular mode

        // Test network error
        try {
            $bad_gateway = new BadNetwork($this->login_info);
            $bad_gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH);
            $response = $bad_gateway->purchase($this->amount, $this->creditcard, $this->options);
            $this->fail("\\AktiveMerchant\\Billing\\Exception expected");
        } catch (Exception $ex) {
            $this->assertEquals("couldn't connect to host",$ex->getMessage());
        }

        // Test XML parsing error on response
        try {
            $bad_gateway = new BadResponse($this->login_info);
            $bad_gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH);
            $response = $bad_gateway->purchase($this->amount, $this->creditcard, $this->options);
            $this->fail("\\AktiveMerchant\\Billing\\Exception expected");
        } catch (Exception $ex) {
            $this->assertEquals("Error parsing XML response from merchant",$ex->getMessage());
        }

        // Test bad request
        try {
            $bad_gateway = new BadRequest($this->login_info);
            $bad_gateway->test_mode(Psigate::TEST_MODE_ALWAYS_AUTH);
            $response = $bad_gateway->purchase($this->amount, $this->creditcard, $this->options);
            $this->fail("\\AktiveMerchant\\Billing\\Exception expected");
        } catch (Exception $ex) {
            $this->assertEquals("Merchant error: PSI-0007:Unable to Parse XML request.",$ex->getMessage());
        }
    }
}
