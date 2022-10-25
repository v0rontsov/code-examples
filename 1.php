<?php
/**
 * @method HomePageCategoryContent|null find($id, $lockMode = null, $lockVersion = null)
 * @method HomePageCategoryContent|null findOneBy(array $criteria, array $orderBy = null)
 * @method HomePageCategoryContent[]    findAll()
 * @method HomePageCategoryContent[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class HomePageCategoryContentRepository extends EntityRepository
{
    public function __construct(EntityManagerInterface $em, Mapping\ClassMetadata $class)
    {
        parent::__construct($em, $class);
    }

    /**
     * @throws NonUniqueResultException
     */
    public function checkForIdenticalOverrides(
        string $uuid,
        int $priority = null,
        CarrierInterface $carrier = null,
        MainCategory $category = null
    ): ?HomePageCategoryContent {
        $queryBuilder = $this->createQueryBuilder('h');
        $parameters = ['uuid' => $uuid];

        $queryBuilder->where('h.uuid != :uuid');

        if ($category) {
            $queryBuilder->andWhere('h.mainCategory = :category');
            $parameters['category'] = $category;
        } elseif ($priority) {
            $queryBuilder->andWhere('h.priority = :priority');
            $parameters['priority'] = $priority;
        }

        if ($carrier) {
            $queryBuilder->andWhere('h.carrier = :carrier');
            $parameters['carrier'] = $carrier;
        } else {
            $queryBuilder->andWhere('h.carrier IS NULL');
        }

        $query = $queryBuilder
            ->setParameters($parameters)
            ->getQuery();

        return $query->getOneOrNullResult();
    }

    public function findUuidOfVideos(CarrierInterface $carrier = null): array
    {
        $queryBuilder = $this->createQueryBuilder('h');

        $queryBuilder
            ->select('v.uuid')
            ->innerJoin('h.videos', 'v');

        if ($carrier) {
            $queryBuilder
                ->where('h.carrier = :carrier')
                ->setParameter('carrier', $carrier);
        } else {
            $queryBuilder->where('h.carrier IS NULL');
        }

        $query = $queryBuilder
            ->groupBy('v.uuid')
            ->getQuery();

        return $query->getResult();
    }

    /**
     * @throws \Exception
     */
    public function findByCarrier(?CarrierInterface $carrier): BatchOfHomePageCategories
    {
        $queryBuilder = $this->createQueryBuilder('h');

        $query = $queryBuilder
            ->where('h.carrier = :carrier OR h.carrier IS NULL')
            ->setParameter('carrier', $carrier)
            ->orderBy('h.priority', 'ASC')
            ->getQuery();

        return new BatchOfHomePageCategories($query->getResult());
    }

    /**
     * @return HomePageCategoryContent[]
     */
    public function findByVideo(UploadedVideo $uploadedVideo): array
    {
        $queryBuilder = $this->createQueryBuilder('h');

        $query = $queryBuilder
            ->where(':video MEMBER OF h.videos')
            ->setParameter('video', $uploadedVideo)
            ->getQuery();

        return $query->getResult();
    }
}
