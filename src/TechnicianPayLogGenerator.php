<?php

namespace App;

class TechnicianPayLogGenerator
{
    // for testing send in $production_report = ['records_off' => 0], and $production_logs = []
    public function getStandardProductionLogs(array $productionReport, array $productionLogs): array
    {
        $standardSubscriptions = $this->getStandardSubscriptions();
        $productionReport['records_off'] += count($standardSubscriptions);

        $subscriptionsIds = [];
        foreach ($standardSubscriptions as $subscriptions) {
            $subscriptionsIds[] = $subscriptions['subscription_id'];
        }

        $pestroutes_subscriptions = $this->getBulkSubscriptions($subscriptionsIds);
        // check the values against the query results
        
        foreach ($standardSubscriptions as $standard_production_subscription) {
            $log = [
                'subscription_id' => $standard_production_subscription->subscriptionID,
                'customer_id' => $standard_production_subscription->customerID,
                'office_id' => $standard_production_subscription->officeID,
                'ticket_template_id' => $standard_production_subscription->ticketID,
                'billing_frequency' => $standard_production_subscription->billingFrequency,
                'service_charge' => $standard_production_subscription->serviceCharge,
                'subtotal' => $standard_production_subscription->subTotal,
                'frequency' => $standard_production_subscription->serviceFrequency,
                'production_current_value' => $standard_production_subscription->productionCurrentValue,
                'production_value_to_set' => -1
            ];
            // handle problems if mismatch
            $pestroutes_subscription = $pestroutes_subscriptions->firstWhere('subscriptionID', $standard_production_subscription->subscriptionID);
            $production_logs[] = $this->generateStandardProductionLogs($standard_production_subscription, $pestroutes_subscription, $log);
        }
        return [$production_report, $production_logs];
    }

    /**
     * Get the custom production subscriptions/tickets that need to be fixed
     * and collect them into the production_logs array
     */
    public function getCustomProductionLogs(array $production_report, array $production_logs): array
    {
        $custom_production_subscriptions = $this->production_records_provider->getCustomProduction();
        $production_report['records_off'] += $custom_production_subscriptions->count();

        $pestroutes_subscriptions = $this->pestroutes_api->getBulkSubscriptions($custom_production_subscriptions->pluck('subscriptionID'));
        // check the values against the query results
        foreach ($custom_production_subscriptions as $custom_production_subscription) {
            $log = [
                'subscription_id' => $custom_production_subscription->subscriptionID,
                'customer_id' => $custom_production_subscription->customerID,
                'office_id' => $custom_production_subscription->officeID,
                'ticket_template_id' => $custom_production_subscription->ticketID,
                'billing_frequency' => $custom_production_subscription->billingFrequency,
                'service_charge' => $custom_production_subscription->serviceCharge,
                'subtotal' => $custom_production_subscription->subTotal,
                'frequency' => $custom_production_subscription->serviceFrequency,
                'production_current_value' => $custom_production_subscription->productionCurrentValue,
                'production_value_to_set' => $custom_production_subscription->customProductionValueShouldBe
            ];
            // handle problems if mismatch
            $subscription = $pestroutes_subscriptions->firstWhere('subscriptionID', $custom_production_subscription->subscriptionID);
            $production_logs[] = $this->generateCustomProductionLogs($custom_production_subscription, $subscription, $log);
        }
        return [$production_report, $production_logs];
    }

    /**
     * Saves the report and associated logs to the database
     */
    public function storeReportAndLogs(array $production_report, array $logs): ProductionReport // own class **
    {
        // save the report and logs to the database
    }

    public function startBatchOfJobs(ProductionReport $production_report, array $production_logs): Batch // own class **
    {
        // create a batch
    }

    private function generateStandardProductionLogs(object $dw_data, object $pr_data = null, array $log): array
    {
        // @ProductionLogErrors = $log['failed_reason']
        // check if PR doesn't have the record we're looking for
        if (is_null($pr_data)) {
            $log['failed_reason'] = "PestRoutes record missing";
            $log['skipped'] = true;
            return $log;
        }

        // if the ticket from Pestroutes is not standard production, use custom
        if (in_array($pr_data->billingFrequency, ProductionLog::CUSTOM_BILLING_FREQUENCY)) {
            return $this->generateCustomProductionLogs($dw_data, $pr_data, $log);
        }

        if ($dw_data->ticketID != $pr_data->ticketID) {
            $log['failed_reason'] = "ticket template id mismatch";
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == "CUSTOM") {
            $log['failed_reason'] = 'Subscription service frequency is now "CUSTOM"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->billingFrequency == "CUSTOM") {
            $log['failed_reason'] = 'Subscription billing frequency is now "CUSTOM"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == -1) {
            $log['failed_reason'] = 'Subscription service frequency is now "As Needed"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == 0) {
            $log['failed_reason'] = 'Subscription service frequency is now "OneTime"';
            $log['skipped'] = true;
            return $log;
        }

        $sub_total_mismatch = $dw_data->subTotal != $pr_data->subTotal;
        $service_frequency_mismatch = $dw_data->serviceFrequency != $pr_data->frequency;
        $billing_frequency_mismatch = $dw_data->billingFrequency != $pr_data->billingFrequency;
        $service_charge_mismatch = $dw_data->serviceCharge != $pr_data->serviceCharge;
        $production_value_mismatch = $dw_data->productionCurrentValue != $pr_data->productionValue;
        if ($service_frequency_mismatch || $billing_frequency_mismatch || $service_charge_mismatch || $production_value_mismatch || $sub_total_mismatch) { // if any true
            $log['dw_pestroutes_data_mismatch'] = true;
            $log['pestroutes_billing_frequency'] = $pr_data->billingFrequency;
            $log['pestroutes_service_charge'] = $pr_data->serviceCharge;
            $log['pestroutes_frequency'] = $pr_data->frequency;
            $log['pestroutes_production_value'] = $pr_data->productionValue;

            // reset production value
            $log['production_value_to_set'] = -1; // put in production log model as a constant
        }
        // if there aren't any changes for pestroutes, skip this one
        $pestroutes_production_value = $log['pestroutes_production_value'] ?? null;
        $production_value_to_set = $log['production_value_to_set'];
        $same_value = (isset($pestroutes_production_value) && $production_value_to_set == $pestroutes_production_value);
        if ($same_value) {
            $log['skipped'] = true;
        }

        return $log;
    }

    private function generateCustomProductionLogs(object $dw_data, object $pr_data = null, array $log): array
    {
        // @ProductionLogErrors = $log['failed_reason']
        // check if PR doesn't have the record we're looking for
        if (is_null($pr_data)) {
            $log['failed_reason'] = "PestRoutes record missing";
            $log['skipped'] = true;
            return $log;
        }

        // if the ticket from Pestroutes is not custom production, use standard
        if (in_array($pr_data->billingFrequency, ProductionLog::STANDARD_BILLING_FREQUENCY)) {
            return $this->generateStandardProductionLogs($dw_data, $pr_data, $log);
        }

        if ($dw_data->ticketID != $pr_data->ticketID) {
            $log['failed_reason'] = "ticket template id mismatch";
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == "CUSTOM") {
            $log['failed_reason'] = 'Subscription service frequency is now "CUSTOM"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->billingFrequency == "CUSTOM") {
            $log['failed_reason'] = 'Subscription billing frequency is now "CUSTOM"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == -1) {
            $log['failed_reason'] = 'Subscription service frequency is now "As Needed"';
            $log['skipped'] = true;
            return $log;
        }

        if ($pr_data->frequency == 0) {
            $log['failed_reason'] = 'Subscription service frequency is now "OneTime"';
            $log['skipped'] = true;
            return $log;
        }

        $sub_total_mismatch = $dw_data->subTotal != $pr_data->subTotal;
        $service_frequency_mismatch = $dw_data->serviceFrequency != $pr_data->frequency;
        $billing_frequency_mismatch = $dw_data->billingFrequency != $pr_data->billingFrequency;
        $service_charge_mismatch = $dw_data->serviceCharge != $pr_data->serviceCharge;
        $production_value_mismatch = $dw_data->productionCurrentValue != $pr_data->productionValue;
        if ($service_frequency_mismatch || $billing_frequency_mismatch || $service_charge_mismatch || $production_value_mismatch || $sub_total_mismatch) { // if any true
            $log['dw_pestroutes_data_mismatch'] = true;
            $log['pestroutes_billing_frequency'] = $pr_data->billingFrequency;
            $log['pestroutes_service_charge'] = $pr_data->serviceCharge;
            $log['pestroutes_frequency'] = $pr_data->frequency;
            $log['pestroutes_production_value'] = $pr_data->productionValue;
            // @DataComparisonWhichOneWins
            // recalculate production value
            $log['production_value_to_set'] = round($pr_data->subTotal * ($pr_data->frequency / $pr_data->billingFrequency), 2);
        }

        // if there aren't any changes for pestroutes, skip this one
        $pestroutes_production_value = $log['pestroutes_production_value'] ?? null;
        $production_value_to_set = $log['production_value_to_set'];
        $same_value = (isset($pestroutes_production_value) && $production_value_to_set == $pestroutes_production_value);
        if ($same_value) {
            $log['skipped'] = true;
        }

        return $log;
    }

    private function getStandardProduction(): array
    {
        // Pretend like this is an SQL query ******
        return [
            [
                'subscription_id' => 245734,
                'customer_id' => 35627,
                'office_id' => 18,
                'billing_frequency' => -1,
                'service_charge' => 50.55,
                'subtotal' => 50.55,
                'service_frequency' => 30,
                'custom_technician_pay_to_set' => -1,
                'technician_pay' => 50.55,
                'ticket_id' => 56738,
            ]
        ];
    }
    
    private function getCustomProduction(): array
    {
        // Pretend like this is an SQL query ******
        return [
            [
                'subscription_id' => 478592,
                'customer_id' => 35627,
                'office_id' => 18,
                'billing_frequency' => 30,
                'service_charge' => 50,
                'subtotal' => 50,
                'service_frequency' => 90,
                'custom_technician_pay_to_set' => 150,
                'technician_pay' => 50.55,
                'ticket_id' => 47839,
            ]
        ];
    }

    public function getBulkSubscriptions(array $subscription_ids): array
    {
        // Act as if this is a call to an API
        return [
            [
                'subscriptionID' => 245734,
                'billingFrequency' => -1,
                'serviceCharge' => 50.55,
                'subTotal' => 50.55,
                'frequency' => 30,
                'productionValue' => 50.55,
                'ticketID' => 56738,
            ],
            [
                'subscriptionID' => 478592,
                'billingFrequency' => 30,
                'serviceCharge' => 50,
                'subTotal' => 50,
                'frequency' => 90,
                'productionValue' => 50.55,
                'ticketID' => 47839,
            ]
        ];
    }
}
