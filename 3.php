<?php

class FacebookController extends Controller
{
    /**
     * @var PageRepository
     */
    private $pageRepository;

    /**
     * @var SegmentRepository
     */
    private $segmentRepository;

    /**
     * @var CommandBus
     */
    private $bus;

    /**
     * @var Manager
     */
    private $fractal;

    /**
     * @var FacebookService
     */
    private $facebook;

    /**
     * @var Dispatcher
     */
    private $dispatcher;

    public function __construct(
        PageRepository $pageRepository,
        SegmentRepository $segmentRepository,
        CommandBus $bus,
        Manager $fractal,
        FacebookService $facebook,
        Dispatcher $dispatcher
    ) {
        $this->pageRepository = $pageRepository;
        $this->segmentRepository = $segmentRepository;
        $this->bus = $bus;
        $this->fractal = $fractal;
        $this->facebook = $facebook;
        $this->dispatcher = $dispatcher;
    }

    public function getPages()
    {
        $account = $this->currentAccount();

        $resource = new Collection($account->pages, new FacebookPageTransformer());
        return $this->fractal->createData($resource)->toJson();
    }

    public function getFacebookPages($accessToken)
    {
        $oauth = $this->facebook->getOAuthClient();
        $longLiveToken = $oauth->getLongLivedAccessToken($accessToken);
        $api = $this->facebook->getAdsApi($longLiveToken->getValue());
        $accounts = $this->facebook->getUserAccounts($api);

        $pages = $accounts->getContent();

        $this->dispatcher->dispatch([new PagesAccessTokensChanged($pages['data'])]);

        return response()->json($pages);
    }

    public function storePage(Request $request)
    {
        $account = $this->currentAccount();

        $fbPageId = $request->input("id");
        $fbPageName = $request->input("name");
        $accessToken = $request->input('access_token');
        $facebookUserId = $request->input('user_id');

        try {
            $command = new CreatePageCommand($account, $fbPageId, $fbPageName, $accessToken, $facebookUserId);
            $this->bus->execute($command);
            return response()->json(['success' => true]);
        } catch (ModelAlreadyExistsException $e) {
            return response()->json([
                'error' => [
                    'code' => 409,
                    'message' => 'A page with given FB ID already exists.'
                ]
            ], 409);
        }
    }

    public function deletePage($id)
    {
        try {
            $page = $this->pageRepository->useAccount($this->currentAccount())->findByFbId($id);
            $this->pageRepository->destroy($page->id);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'error' => [
                    'code' => 404,
                    'message' => 'Model not found.'
                ]
            ], 404);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verification that it's Facebook making calls
     *
     * @param Request $request
     */
    public function subscribeVerification(Request $request)
    {
        if ($request->input('hub_challenge')) {
            $challenge = $request->input('hub_challenge');
            $verify_token = $request->input('hub_verify_token');

            if ($verify_token === config('facebook.hub_verification')) {
                echo $challenge;
            }
        }
    }

    /**
     * Callback for when lead as been generated on Facebook
     *
     * @param Request $request
     */
    public function webhook(Request $request)
    {
        try {
            if ($request->get('object') != 'page') {
                return response()->json(['error' => "Wrong object type: {$request->get('object')}"], 500);
            }

            foreach ($request->get('entry') as $entry) {
                $leads = $this->bus->execute(new CreateLeadsFromEntryCommand($entry));
                $this->bus->execute(new RegisterSubscribersFromLeadsCommand($leads));
            }
        } catch (Exception $e) {
            report($e);
            return response('', Response::HTTP_OK);
        }
    }

    /**
     * Callback for when user removes app from Facebook
     *
     * @param Request $request
     */
    public function deauthorized(Request $request)
    {
        $signedRequest = $request->get('signed_request');
        list($encodedSignature, $payload) = explode('.', $signedRequest);

        $signature = base64_decode(strtr($encodedSignature, '-_', '+/'));
        $data = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);

        // Validate payload signature
        $expectedSignature = hash_hmac('sha256', $payload, config('facebook.app_secret'), $raw = true);

        if ($signature !== $expectedSignature) {
            return abort(403);
        }

        $this->pageRepository->destroyByUserId($data['user_id']);
    }

    public function uploadSubscribers(Request $request)
    {
        $this->segmentRepository->useAccount($this->currentAccount());

        $segmentId = $request->input('segment_id');
        $accessToken = $request->input('access_token');
        $adAccountId = $request->input('account_id');

        try {
            $segment = $this->segmentRepository->find($segmentId);
            $methodName = 'replaceSubscribersInAudience';
            $audienceId = $segment->audience_id;

            if (!$segment->audience_id
                || $this->facebook->checkAudienceStatus($audienceId, $accessToken) == '100') {
                $audienceId = $this->facebook
                    ->createAudience($segment, $adAccountId, $accessToken);
                $methodName = 'uploadSubscribersToAudience';

                $this->bus->execute(
                    new SyncSegmentAudienceCommand(
                        $segment,
                        $this->currentAccount(),
                        $audienceId
                    )
                );
            }

            if ($this->facebook->checkAudienceStatus($audienceId, $accessToken) != '200') {
                throw new FacebookSDKException('Custom Audience is not ready for updating');
            }

            $this->bus->queue(new UploadSubscribersFromFilterCommand(
                $segment,
                $methodName,
                $audienceId,
                $accessToken
            ));
        } catch (FacebookSDKException $e) {
            return response()->json([
                'error' => [
                    'code' => 100,
                    'message' => $e->getMessage()
                ]
            ]);
        }

        return response()->json(['success' => true]);
    }

    public function getAdAccounts($accessToken)
    {
        $accounts = $this->facebook->getAdAccounts($accessToken);

        return response()->make($accounts);
    }
}
