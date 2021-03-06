<?php
/**
 * 
 * 
 * @author Carsten Brandt <mail@cebe.cc>
 */

namespace app\controllers;


use Yii;
use yii\filters\VerbFilter;
use yii\web\BadRequestHttpException;
use yii\web\Controller;
use yii\web\Response;

class IssuesController extends Controller
{
	public function behaviors()
	{
		return [
			'verb' => [
				'class' => VerbFilter::className(),
				'actions' => [
					'index' => ['post'],
				],
			],
		];
	}

	public function actionIndex()
	{
		\Yii::$app->response->format = Response::FORMAT_JSON;

		// content of $params should look like here: https://developer.github.com/v3/activity/events/types/#issuesevent
		$params = \Yii::$app->request->bodyParams;
		$event = \Yii::$app->request->headers->get('X-Github-Event');
		if (!$event) {
			\Yii::warning('event request without X-Github-Event header.');
			throw new BadRequestHttpException('Event request without X-Github-Event header.');
		}

		Yii::$app->github->verifyRequest(Yii::$app->request->rawBody);

		if ($event === 'ping') {
			return ['success' => true, 'action' => 'pong'];
		}

		if ($event !== 'issues') {
			throw new BadRequestHttpException('Only issues events should be deployed here.');
		}

		if ($params['sender']['login'] === Yii::$app->params['github_username']) {
			\Yii::warning('ignoring event triggered by myself.');
			return ['success' => true, 'action' => 'ignored'];
		}

		switch($params['action'])
		{
			case 'labeled':
				// if label is added, check for actions

				if (isset($params['label'])) {
					foreach(\Yii::$app->params['actions'] as $action) {
						if ($params['label']['name'] == $action['label']) {
							$this->performAction($action, $params);
						}
					}
				}

				return ['success' => true, 'action' => 'processed'];
				break;
		}

		return ['success' => true, 'action' => 'ignored'];
	}

	protected function performAction($action, $params)
	{
		switch($action['action'])
		{
			case 'comment':
				sleep(2); // wait 2sec before reply to have github issue events in order
				$this->replyWithComment($params['repository'], $params['issue'], $action['comment']);
				if ($action['close']) {
					sleep(2); // wait 2sec before reply to have github issue events in order
					$this->closeIssue($params['repository'], $params['issue']);
				}
				break;
			case 'move':
				if ($params['issue']['state'] !== 'open') {
					// do not move issue if it is closed, allow editing labels in closed state
					break;
				}
				sleep(2); // wait 2sec before reply to have github issue events in order
				$this->moveIssue($params['repository'], $action['repo'], $params['issue'], $params['sender']);
				break;
		}

	}

	protected function replyWithComment($repository, $issue, $comment)
	{
		/** @var $client \Github\Client */
		$client = Yii::$app->github->client();

		$api = new \Github\Api\Issue($client);
		$api->comments()->create($repository['owner']['login'], $repository['name'], $issue['number'], [
			'body' => $comment,
		]);
		Yii::info("commented on issue {$repository['owner']['login']}/{$repository['name']}#{$issue['number']}.", 'action');
	}

	protected function closeIssue($repository, $issue)
	{
		/** @var $client \Github\Client */
		$client = Yii::$app->github->client();

		$api = new \Github\Api\Issue($client);
		$api->update($repository['owner']['login'], $repository['name'], $issue['number'], [
			'state' => 'closed',
		]);
		Yii::info("closed issue {$repository['owner']['login']}/{$repository['name']}#{$issue['number']}.", 'action');
	}

	protected function moveIssue($fromRepository, $toRepository, $issue, $sender)
	{
		// do not move issue if from and to repo are the same (prevent loops)
		if ("{$fromRepository['owner']['login']}/{$fromRepository['name']}" === $toRepository) {
			Yii::warning("did NOT move issue {$fromRepository['owner']['login']}/{$fromRepository['name']}#{$issue['number']} to {$toRepository}.", 'action');
			return;
		}
		// also do not move issues created by the bot itself (prevent loops)
		if ($issue['user']['login'] === Yii::$app->params['github_username']) {
			Yii::warning("did NOT move issue {$fromRepository['owner']['login']}/{$fromRepository['name']}#{$issue['number']} to {$toRepository} because it was created by me.", 'action');
			return;
		}

		/** @var $client \Github\Client */
		$client = Yii::$app->github->client();

		$api = new \Github\Api\Issue($client);
		list($toUser, $toRepo) = explode('/', $toRepository);
		$newIssue = $api->create($toUser, $toRepo, [
			'title' => $issue['title'],
			'body' => 'This issue has originally been reported by @' . $issue['user']['login'] . ' at ' . $issue['html_url'] . ".\n"
				. 'Moved here by @' . $sender['login'] . '.'
				. "\n\n-----\n\n"
				. $issue['body'],
			'labels' => array_map(function($i) { return $i['name']; }, $issue['labels']),
		]);
		Yii::info("moved issue {$fromRepository['owner']['login']}/{$fromRepository['name']}#{$issue['number']} to {$toRepository}#{$newIssue['number']}.", 'action');
		sleep(2); // wait 2sec before reply to have github issue events in order
		$this->replyWithComment($fromRepository, $issue, 'Issue moved to ' . $newIssue['html_url']);
		sleep(2); // wait 2sec before reply to have github issue events in order
		$this->closeIssue($fromRepository, $issue);
	}
}