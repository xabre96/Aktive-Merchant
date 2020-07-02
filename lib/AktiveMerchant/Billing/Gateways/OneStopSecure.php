<?php

namespace AktiveMerchant\Billing\Gateways;

use AktiveMerchant\Billing\Gateway;
use AktiveMerchant\Http\Request;

class OneStopSecure extends Gateway {
    const TEST_URL = 'https://uwa-dev.onestopsecure.com/UWA/tranadd';
    const LIVE_URL = 'https://payments.uwa.edu.au/integrated/tranadd';

    protected $options;
    protected $post = array(
        'tran-type' => 10437
    );

    function __construct($options = array()) {
        $this->required_options('uds_action, glcode', $options);
        $this->options = $options;
    }

    function getRedirectURI($money, $data) {
        $this->build_post_data($money, $data);
        return $this->build_redirect_uri();
    }

    protected function post_data() {
        return $this->urlize($this->post);
    }

    protected function build_post_data($money, $data) {
        $this->post['CustomerId'] = $data['customerId'];
        $this->post['FNAME'] = $data['firstName'];
        $this->post['SNAME'] = $data['lastName'];
        $this->post['Email'] = $data['email'];
        $this->post['UnitAmountInctax'] = $money + 12;
    }

    protected function build_redirect_uri() {
        if (isset($this->options['devUrl']) && isset($this->options['prodUrl'])) {
            $url = $this->isTest() ? $this->options['devUrl'] : $this->options['prodUrl'];
        } else {
            $url = $this->isTest() ? self::TEST_URL : self::LIVE_URL;
        }

        return $url . '?' . $this->post_data();
    }
}
