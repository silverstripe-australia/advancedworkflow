<?php

namespace Symbiote\AdvancedWorkflow\Tasks;

use SilverStripe\Dev\BuildTask;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Email\Email;
use SilverStripe\PolyExecution\PolyOutput;
use SilverStripe\ORM\FieldType\DBDatetime;
use Symbiote\AdvancedWorkflow\DataObjects\WorkflowInstance;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;

/**
 * A task that sends a reminder email to users assigned to a workflow that has
 * not been actioned for n days.
 *
 * @package advancedworkflow
 */
class WorkflowReminderTask extends BuildTask
{
    protected string $title = 'Workflow Reminder Task';

    protected static string $description = 'Sends out workflow reminder emails to stale workflow instances';

    protected static string $commandName = 'WorkflowReminderTask';

    protected function execute(InputInterface $input, PolyOutput $output): int
    {
        $sent = 0;
        if (WorkflowInstance::get()->count()) { // Don't attempt the filter if no instances -- prevents a crash
            $active = WorkflowInstance::get()
                ->innerJoin('WorkflowDefinition', '"DefinitionID" = "WorkflowDefinition"."ID"')
                ->filter(array(
                    'WorkflowStatus' => array('Active', 'Paused')
                ))->where('RemindDays > 0');

            if ($active->exists()) {
                foreach ($active as $instance) {
                    $edited = strtotime($instance->LastEdited ?? '');
                    $days   = $instance->Definition()->RemindDays;

                    if ($edited + $days * 3600 * 24 > DBDatetime::now()->getTimestamp()) {
                        continue;
                    }

                    $email   = new Email();
                    $bcc     = '';
                    $members = $instance->getAssignedMembers();
                    $target  = $instance->getTarget();

                    if (!$members || !count($members ?? [])) {
                        continue;
                    }

                    $email->setSubject("Workflow Reminder: $instance->Title");
                    $email->setBCC($members->column('Email'));
                    $email->setHTMLTemplate('email\\WorkflowReminderEmail');
                    $email->setData(array(
                        'Instance' => $instance,
                        'Link' => $target instanceof SiteTree ? "admin/show/$target->ID" : '',
                        'Diff' => $instance->getTargetDiff()
                    ));

                    $email->send();


                    $sent++;

                    $instance->LastEdited = DBDatetime::now()->getTimestamp();
                    $instance->write();
                }
            }
        }
        $output->writeln("Sent $sent workflow reminder emails.");
        return Command::SUCCESS;
    }
}
