<?php

namespace Shtumi\UsefulBundle\Controller;

use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use Symfony\Component\HttpFoundation\Response;

class DependentFilteredEntityController extends Controller
{
    const DQL_PARAMETER_PREFIX = 'param_';

    public function getOptionsAction()
    {
        $em = $this->get('doctrine')->getManager();
        $request = $this->getRequest();
        $translator = $this->get('translator');

        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');
        $fallbackParentId = $request->get('fallback_parent_id');
        $empty_value  = $request->get('empty_value');

        $excludedEntityId = $request->get('excluded_entity_id');
        $isTranslationDomainEnabled = $request->get('choice_translation_domain');
        $choiceTitleTranslationPart = $request->get('choice_title_translation_part');

        $entities = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $entity_inf = $entities[$entity_alias];

        $selectedResultService = $entity_inf['selected_result_service'];

        //set the fallback
        if ($entity_inf['fallback_alias'] !== null && !empty($fallbackParentId) && empty($parent_id)) {
            $parent_id = $fallbackParentId;
            $entity_inf = $entities[$entity_inf['fallback_alias']];
        }

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            if (false === $this->get('security.context')->isGranted( $entity_inf['role'] )) {
                throw new AccessDeniedException();
            }
        }

        /** @var QueryBuilder $qb */
        $qb = $this->getDoctrine()
            ->getRepository($entity_inf['class'])
            ->createQueryBuilder('e');

        //if many to many
        if($entity_inf['many_to_many']['active'] == true) {
            $mtmEntity = $entity_inf['many_to_many']['entity'];
            $mtmProperty = $entity_inf['many_to_many']['property'];

            //make (array)$joinTableResults from mtmEntity to use it into IN (:results) of entity's $qb
            $qbjt = $this->getDoctrine()
            ->getRepository($mtmEntity)
            ->createQueryBuilder('jt');

            $qbjt
                ->where('jt.' . $entity_inf['parent_property'] . ' = :parent_id');

            $qbjt
                ->setParameter('parent_id', $parent_id);

            $results = $qbjt->getQuery()->getResult();

            $joinTableResults = [];
            foreach($results as $result) {
                $getter = $this->getGetterName($mtmProperty);
                $joinTableResults[] = $result->$getter()->getId(); //здесь ПОПРАВИТЬ
            }

            $qb
                ->andWhere('e.id IN (:results)');
            $qb
                ->setParameter('results', $joinTableResults);
        } else {
            if($entity_inf['grandparent_property']) {
                $qb
                    ->leftJoin("e." . $entity_inf['parent_property'], "parent")
                    ->where("parent." . $entity_inf['grandparent_property'] . ' = :parent_id');
            } else {
                $qb
                    ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id');
            }

            $qb
                ->setParameter('parent_id', $parent_id);
        }

        $qb
            ->andWhere('e.id != :excluded_entity_id');

        $qb
            ->setParameter('excluded_entity_id', $excludedEntityId);

        $qb
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction']);

        //add the filters to a query
        foreach ($entity_inf['child_entity_filters'] as $key => $filter) {
            $parameterName = DependentFilteredEntityController::DQL_PARAMETER_PREFIX . $filter['property'] . $key;

            $qb
                ->andWhere('e.' . $filter['property'] . ' ' . $filter['sign'] . ' :' . $parameterName)
                ->setParameter($parameterName, $filter['value']);
        }

        if (null !== $entity_inf['callback']) {
            $repository = $qb->getEntityManager()->getRepository($entity_inf['class']);

            if (!method_exists($repository, $entity_inf['callback'])) {
                throw new \InvalidArgumentException(sprintf('Callback function "%s" in Repository "%s" does not exist.', $entity_inf['callback'], get_class($repository)));
            }

            //dql callback starts here
            $repository->$entity_inf['callback']($qb);
        }

        $results = $qb->getQuery()->getResult();

        $selectedResultId = null;

        if ($selectedResultService) {
            $selectedResultId = $this->get($selectedResultService)->findOptionIdToSelect($results);
        }

        if (empty($results)) {
            return new Response('<option value="">' . $translator->trans($entity_inf['no_result_msg']) . '</option>');
        }

        $html = '';
        if ($empty_value !== false)
            $html .= '<option value="">' . $translator->trans($empty_value) . '</option>';

        $getter =  $this->getGetterName($entity_inf['property']);

        foreach($results as $key => $result)
        {
            if ($entity_inf['property'])
                $res = $result->$getter();
            else $res = (string)$result;

            //check if translation is enabled
            if ($isTranslationDomainEnabled) {
                if ($choiceTitleTranslationPart) {
                    $res = $translator->trans((string)$choiceTitleTranslationPart) . str_replace($choiceTitleTranslationPart, '', $res);
                } else {
                    $res = $translator->trans((string)$res);
                }
            }

            $optionString = "<option value=\"%d\">%s</option>";

            //auto select first result (if it's enabled in the config.yml)
            if (($entity_inf['auto_select_first_result'] && $key === 0) || $result->getId() === $selectedResultId) {
                $optionString = "<option value=\"%d\" selected>%s</option>";
            }

            $html = $html . sprintf($optionString, $result->getId(), $res);
        }

        return new Response($html);
    }

    public function getJSONAction()
    {
        /** @var \Doctrine\ORM\EntityManager $em */
        $em = $this->get('doctrine.orm.entity_manager');
        $request = $this->get('request');

        $entity_alias = $request->get('entity_alias');
        $parent_id    = $request->get('parent_id');

        $entities = $this->get('service_container')->getParameter('shtumi.dependent_filtered_entities');
        $entity_inf = $entities[$entity_alias];

        if ($entity_inf['role'] !== 'IS_AUTHENTICATED_ANONYMOUSLY'){
            if (false === $this->get('security.context')->isGranted( $entity_inf['role'] )) {
                throw new AccessDeniedException();
            }
        }

        $term = $request->get('term');
        $maxRows = $request->get('maxRows', 20);

        $like = '%' . $term . '%';

        $property = $entity_inf['property'];
        if (!$entity_inf['property_complicated']) {
            $property = 'e.' . $property;
        }

        $qb = $em->createQueryBuilder()
            ->select('e')
            ->from($entity_inf['class'], 'e')
            ->where('e.' . $entity_inf['parent_property'] . ' = :parent_id')
            ->setParameter('parent_id', $parent_id)
            ->orderBy('e.' . $entity_inf['order_property'], $entity_inf['order_direction'])
            ->setParameter('like', $like )
            ->setMaxResults($maxRows);

        if ($entity_inf['case_insensitive']) {
            $qb->andWhere('LOWER(' . $property . ') LIKE LOWER(:like)');
        } else {
            $qb->andWhere($property . ' LIKE :like');
        }

        $results = $qb->getQuery()->getResult();

        $res = array();
        foreach ($results AS $r){
            $res[] = array(
                'id' => $r->getId(),
                'text' => (string)$r
            );
        }

        return new Response(json_encode($res));
    }

    private function getGetterName($property)
    {
        $parts = explode('_', $property);
        $parts = array_map(
            function($part) {
                return ucfirst($part);
            },
            $parts
        );
        $parts = implode($parts);
        $name = 'get'.$parts;

        return $name;
    }
}
