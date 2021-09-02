<?php

/**
 * @package         Billing
 * @copyright       Copyright (C) 2012 BillRun Technologies Ltd. All rights reserved.
 * @license         GNU Affero General Public License Version 3; see LICENSE.txt
 */

/**
 * Calculates a yearly upfront charge
 *
 * @package  Plans
 * @since    5.2
 */
trait Billrun_Plans_Charge_Arrears_Traits_Custom {
	use Billrun_Plans_Charge_Traits_Custom;

	public function __construct($plan) {
		parent::__construct($plan);;
		$this->recurrenceConfig = $plan['recurrence'];
		$this->updateCycleByConfig($plan);
		$this->setMonthlyCover();
	}

	/**
	 *
	 */
	protected function getTariffForMonthCover($tariff, $startOffset, $endOffset ,$activation = FALSE) {
		$frequency = $this->recurrenceConfig['frequency'];
		return Billrun_Plan::getPriceByTariff($tariff, $startOffset/$frequency, $endOffset/$frequency ,$activation);
	}

}