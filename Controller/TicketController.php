<?php

namespace Hackzilla\Bundle\TicketBundle\Controller;

use Hackzilla\Bundle\TicketBundle\Event\TicketEvent;
use Hackzilla\Bundle\TicketBundle\Form\Type\TicketMessageType;
use Hackzilla\Bundle\TicketBundle\Form\Type\TicketType;
use Hackzilla\Bundle\TicketBundle\Model\TicketMessageInterface;
use Hackzilla\Bundle\TicketBundle\TicketEvents;
use Hackzilla\Bundle\TicketBundle\TicketRole;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\Request;

/**
 * Ticket controller.
 */
class TicketController extends Controller
{
    /**
     * Lists all Ticket entities.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function indexAction(Request $request)
    {
        $userManager = $this->get('hackzilla_ticket.user_manager');
        $ticketManager = $this->get('hackzilla_ticket.ticket_manager');
        $translator = $this->get('translator');

        $ticketState = $request->get('state', $translator->trans('STATUS_OPEN'));
        $ticketPriority = $request->get('priority', null);

        $query = $ticketManager->getTicketList(
            $userManager,
            $ticketManager->getTicketStatus($translator, $ticketState),
            $ticketManager->getTicketPriority($translator, $ticketPriority)
        );

        $pagination = $this->get('knp_paginator')->paginate(
            $query->getQuery(),
            $request->query->get('page', 1)/*page number*/,
            10/*limit per page*/
        );

        return $this->render(
            'HackzillaTicketBundle:Ticket:index.html.twig',
            [
                'pagination'     => $pagination,
                'ticketState'    => $ticketState,
                'ticketPriority' => $ticketPriority,
            ]
        );
    }

    /**
     * Creates a new Ticket entity.
     *
     * @param Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function createAction(Request $request)
    {
        $userManager = $this->get('hackzilla_ticket.user_manager');
        $ticketManager = $this->get('hackzilla_ticket.ticket_manager');

        $ticket = $ticketManager->createTicket();
        $form = $this->createForm(TicketType::class, $ticket);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $message = $ticket->getMessages()->current();
            $message->setStatus(TicketMessageInterface::STATUS_OPEN)
                ->setUser($userManager->getCurrentUser());

            $ticketManager->updateTicket($ticket, $message);

            $event = new TicketEvent($ticket);
            $this->get('event_dispatcher')->dispatch(TicketEvents::TICKET_CREATE, $event);

            return $this->redirect($this->generateUrl('hackzilla_ticket_show', ['ticketId' => $ticket->getId()]));
        }

        return $this->render(
            'HackzillaTicketBundle:Ticket:new.html.twig',
            [
                'entity' => $ticket,
                'form'   => $form->createView(),
            ]
        );
    }

    /**
     * Displays a form to create a new Ticket entity.
     */
    public function newAction()
    {
        $ticketManager = $this->get('hackzilla_ticket.ticket_manager');
        $entity = $ticketManager->createTicket();

        $form = $this->createForm(TicketType::class, $entity);

        return $this->render(
            'HackzillaTicketBundle:Ticket:new.html.twig',
            [
                'entity' => $entity,
                'form'   => $form->createView(),
            ]
        );
    }

    /**
     * Finds and displays a TicketInterface entity.
     *
     * @param int $ticketId
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function showAction($ticketId)
    {
        $ticketManager = $this->get('hackzilla_ticket.ticket_manager');
        $ticket = $ticketManager->getTicketById($ticketId);

        if (!$ticket) {
            return $this->redirect($this->generateUrl('hackzilla_ticket'));
        }

        $userManager = $this->get('hackzilla_ticket.user_manager');
        $userManager->hasPermission($userManager->getCurrentUser(), $ticket);

        $data = ['ticket' => $ticket];

        $message = $ticketManager->createMessage();
        $message->setPriority($ticket->getPriority());
        $message->setStatus($ticket->getStatus());

        if (TicketMessageInterface::STATUS_CLOSED != $ticket->getStatus()) {
            $data['form'] = $this->createMessageForm($message)->createView();
        }

        if ($userManager->getCurrentUser() && $this->get('hackzilla_ticket.user_manager')->hasRole(
                $userManager->getCurrentUser(),
                TicketRole::ADMIN
            )
        ) {
            $data['delete_form'] = $this->createDeleteForm($ticket->getId())->createView();
        }

        return $this->render('HackzillaTicketBundle:Ticket:show.html.twig', $data);
    }

    /**
     * Finds and displays a TicketInterface entity.
     *
     * @param Request $request
     * @param int     $ticketId
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function replyAction(Request $request, $ticketId)
    {
        $userManager = $this->get('hackzilla_ticket.user_manager');
        $ticketManager = $this->get('hackzilla_ticket.ticket_manager');
        $ticket = $ticketManager->getTicketById($ticketId);

        if (!$ticket) {
            throw $this->createNotFoundException($this->get('translator')->trans('ERROR_FIND_TICKET_ENTITY'));
        }

        $user = $userManager->getCurrentUser();
        $userManager->hasPermission($user, $ticket);

        $message = $ticketManager->createMessage($ticket);

        $form = $this->createMessageForm($message);
        $form->handleRequest($request);

        if ($form->isValid()) {
            $message->setUser($user);
            $ticketManager->updateTicket($ticket, $message);

            $this->get('event_dispatcher')->dispatch(TicketEvents::TICKET_UPDATE, new TicketEvent($ticket));

            return $this->redirect($this->generateUrl('hackzilla_ticket_show', ['ticketId' => $ticket->getId()]));
        }

        return $this->showAction($ticket->getId());
    }

    /**
     * Deletes a Ticket entity.
     *
     * @param Request $request
     * @param int     $ticketId
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    public function deleteAction(Request $request, $ticketId)
    {
        $userManager = $this->get('hackzilla_ticket.user_manager');
        $user = $userManager->getCurrentUser();

        if (!\is_object($user) || !$userManager->hasRole($user, TicketRole::ADMIN)) {
            throw new \Symfony\Component\HttpKernel\Exception\HttpException(403);
        }

        $form = $this->createDeleteForm($ticketId);

        if ($request->isMethod('DELETE')) {
            $form->submit($request->request->get($form->getName()));

            if ($form->isValid()) {
                $ticketManager = $this->get('hackzilla_ticket.ticket_manager');
                $ticket = $ticketManager->getTicketById($ticketId);

                if (!$ticket) {
                    throw $this->createNotFoundException($this->get('translator')->trans('ERROR_FIND_TICKET_ENTITY'));
                }

                $ticketManager->deleteTicket($ticket);
                $event = new TicketEvent($ticket);
                $this->get('event_dispatcher')->dispatch(TicketEvents::TICKET_DELETE, $event);
            }
        }

        return $this->redirect($this->generateUrl('hackzilla_ticket'));
    }

    /**
     * Creates a form to delete a Ticket entity by id.
     *
     * @param mixed $id The entity id
     *
     * @return \Symfony\Component\Form\Form The form
     */
    private function createDeleteForm($id)
    {
        return $this->createFormBuilder(['id' => $id])
            ->add('id', HiddenType::class)
            ->getForm()
        ;
    }

    /**
     * @param TicketMessageInterface $message
     *
     * @return \Symfony\Component\Form\Form
     */
    private function createMessageForm(TicketMessageInterface $message)
    {
        $form = $this->createForm(
            TicketMessageType::class,
            $message,
            [
                'new_ticket' => false,
            ]
        );

        return $form;
    }
}
