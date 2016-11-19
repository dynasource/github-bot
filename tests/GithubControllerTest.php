<?php

namespace yiiunit\extensions\githubbot;

use app\commands\GithubController;
use Yii;
use yiiunit\extensions\githubbot\mocks\CachedHttpClientMock;
use yiiunit\extensions\githubbot\mocks\GithubControllerMock;

/**
 * Class GithubControllerTest
 * @package yiiunit\extensions\githubbot
 * @author Boudewijn Vahrmeijer <info@dynasource.eu>
 */
class GithubControllerTest extends TestCase
{
    public function testInit_HookSecretException()
    {
        $this->setExpectedException('yii\base\Exception', 'Config param "hook_secret" is not configured!');
        new GithubController('github', Yii::$app);
    }

    public function testHooks_UndefinedWebUrl()
    {
        $this->mockApplication([
            'params' => [
                'hook_secret' => 'dummy',
            ],
        ]);
        $controller = new GithubController('github', Yii::$app);

        $this->setExpectedException('PHPUnit_Framework_Error_Notice', 'Undefined index: webUrl');
        $controller->hooks();
    }

    public function testHooks()
    {
        $this->mockApplication([
            'params' => [
                'hook_secret' => 'dummy',
                'webUrl' => 'http://localhost',
            ],
        ]);
        $controller = new GithubController('github', Yii::$app);
        $this->assertEquals([
            'issues' => 'http://localhost/index.php?r=issues'
        ], $controller->hooks());
    }

    public function testActionRegister_RequiredGithubComponentException()
    {
        $this->mockApplication([
            'params' => [
                'hook_secret' => 'dummy',
            ],
        ]);
        $controller = new GithubController('github', Yii::$app);
        $this->setExpectedException('\yii\base\UnknownPropertyException', 'Getting unknown property: yii\console\Application::github');
        $controller->runAction('register');
    }

    public function testActionRegister_RequiredRepositoriesParamException()
    {
        $this->mockApplication([
            'components' => [
                'github' => 'app\components\Github',
            ],
            'params' => [
                'hook_secret' => 'dummy',
                'github_token' => 'dummy',
                'github_username' => 'dummy',
            ],
        ]);
        $controller = new GithubController('github', Yii::$app);
        $this->setExpectedException('PHPUnit_Framework_Error_Notice', 'Undefined index: repositories');
        $controller->runAction('register');
    }

    public function testActionRegister_WrongTokenException()
    {
        $this->mockApplication([
            'components' => [
                'github' => 'app\components\Github',
            ],
            'params' => [
                'hook_secret' => 'thisisatestsecret',
                //'github_token' => 'b44b34cebcc98ecaaff364b69ab869381b499322',
                'github_token' => 'wrong' . CachedHttpClientMock::DUMMY_TOKEN,
                'github_username' => 'dynasource-test',
                'repositories' => [
                    'dynasource-test/githook-test',
                ],
                'webUrl' => 'http://www.dynasource.eu/auth',
            ],
        ]);
        $controller = new GithubControllerMock('github', Yii::$app);
        $this->setExpectedException('Github\Exception\RuntimeException', 'Bad credentials', 401);
        $controller->runAction('register');
    }

    public function testActionRegister_()
    {
        $config = [
            'components' => [
                'github' => 'app\components\Github',
            ],
            'params' => [
                'hook_secret' => 'thisisatestsecret',
                'github_token' => CachedHttpClientMock::DUMMY_TOKEN,
                'github_username' => 'dynasource-test',
                'repositories' => [
                    'repo-test/hook-test',
                ],
                'webUrl' => 'http://www.dynasource.eu/auth',
            ],
        ];
        $this->mockApplication($config);
        $controller = new GithubControllerMock('github', Yii::$app);
        $controller->runAction('register');
        $actual = $controller->flushStdOutBuffer();
        $this->assertEquals("registering issues hook on " . $config['params']['repositories'][0] . "...added.\n", $actual);
    }
}