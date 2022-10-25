<?php


interface FacebookService
{
    public function getApp();

    public function getOAuthClient();

    //public function initAdsApi($accessToken);
    public function getAdsApi($accessToken);

    public function getUserAccounts(Api $api);

    public function getLeadFormData($leadId, $accessToken);

    public function getAdAccounts($accessToken);

    public function createAudience($segment, $adAccountId, $accessToken);

    public function replaceSubscribersInAudience(
        string $audienceId,
        string $accessToken,
        array $data
    );

    public function uploadSubscribersToAudience(
        string $audienceId,
        string $accessToken,
        array $data
    );

    public function checkAudienceStatus($audienceId, $accessToken);
}
