<?php

class AutoLoginService
{
    private UserAutologinRepository $autologinRepository;
    private EmailSender $emailSender;
    private EntityManagerInterface $em;
    private UrlGeneratorInterface $router;

    public function __construct(
        UserAutologinRepository $autologinRepository,
        EmailSender $emailSender,
        EntityManagerInterface $em,
        UrlGeneratorInterface $router
    ) {
        $this->autologinRepository = $autologinRepository;
        $this->emailSender = $emailSender;
        $this->em = $em;
        $this->router = $router;
    }

    /**
     * @throws \Twig\Error\SyntaxError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\LoaderError
     */
    public function sendAutologinEmail(Admin $admin, int $autoLoginLinkLifeTime, string $contactEmail): bool
    {
        $userAutologin = $this->autologinRepository->findSessionLogin($admin, $autoLoginLinkLifeTime);

        if (!$userAutologin instanceof UserAutologin) {
            $time = new DateTime('now');
            $time->format('Y-m-d H:i:s');
        } else {
            $time = $userAutologin->getTime();
        }

        $userAutologin = new UserAutologin(UuidGenerator::generate());
        $userAutologin->setTime($time);
        $userAutologin->setHash(
            md5($admin->getId() . '|' . mt_rand(1000, 100000) . '|' . $time->format('Y-m-d H:i:s'))
        );
        $userAutologin->setAdmin($admin);
        $this->em->persist($userAutologin);
        $this->em->flush();
        $autoLoginUrl = $this->router->generate(
            'autologin_url_check',
            ['auth' => $userAutologin->getHash()],
            UrlGeneratorInterface::ABSOLUTE_URL
        );
        $data = [
            'autoLoginUrl' => $autoLoginUrl,
        ];

        return $this->emailSender->sendMessage(
            "@App/Common/autoLoginEmailBody.html.twig",
            $data,
            'Login link 100 Sport Store',
            $contactEmail,
            $admin->getEmail(),
            [],
        );
    }
}
