<?php

namespace KnpU\ActivityRunner;

use KnpU\ActivityRunner\Activity\CodingChallenge\CodingExecutionResult;
use KnpU\ActivityRunner\Worker\Executor\CodeExecutor;
use KnpU\ActivityRunner\Worker\WorkerBag;
use Symfony\Component\ExpressionLanguage\ExpressionFunction;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;

/**
 * Actually executes an Activity and then passes it to the suite for validation
 *
 * This relies on "workers" behind the scenes - e.g. there is a different worker for
 * "php" activities, versus Twig activities, etc.
 *
 * @author Kristen Gilden <kristen.gilden@gmail.com>
 */
class ActivityRunner
{
    /**
     * @var WorkerBag
     */
    protected $workerBag;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    private $projectRootDir;

    /**
     * @param WorkerBag $workerBag
     */
    public function __construct(WorkerBag $workerBag, \Twig_Environment $twig, $projectRootDir) {
        $this->workerBag = $workerBag;
        $this->twig = $twig;
        $this->projectRootDir = $projectRootDir;
    }

    /**
     * @param Activity $activity
     *
     * @return Result
     */
    public function run(Activity $activity)
    {
        $challenge = $activity->getChallengeObject();
        $worker = $this->getWorker($challenge->getExecutionMode());

        // 1) write all of the files        (different for contexts)
        // 2) call setupContext()           (different for contexts)
        // 3) call grade() and use results  (different for contexts)

        $filesToWrite = $this->getFilesToCreate($activity);
        $initialExecutionResult = new CodingExecutionResult($filesToWrite);

        // serialize this, so we can easily fetch the input files
        $filesToWrite['executionResult.cache'] = serialize($initialExecutionResult);

        // write the challenge class name
        $filesToWrite['ChallengeClass.php'] = $activity->getChallengeClassContents();

        // write our executor.php and execute that
        $executionCode = $this->twig->render('code_executor.php.twig', array(
            'workerClass'  => get_class($worker),
            'projectPath' => $this->projectRootDir,
            'challengeFilename' => 'ChallengeClass.php',
            'challengeClassName' => $activity->getChallengeClassName(),
            'entryPointFilename' => $challenge->getFileBuilder()->getEntryPointFilename()
        ));
        $filesToWrite['execution.php'] = $executionCode;

        $codeExecutor = new CodeExecutor($filesToWrite, 'execution.php');
        $executionResult = $codeExecutor->executePhpProcess();

        $result = new Result($activity);
        // clean things up just in case - but the worker should take care of this
        $result->setLanguageError(
            self::cleanError(
                $executionResult->getLanguageError(),
                $executionResult->getCodeDirectory()
            )
        );
        $result->setGradingError($executionResult->getGradingError());
        $result->setOutput($executionResult->getOutput());

        return $result;
    }

    /**
     * @param string $workerName
     *
     * @return \KnpU\ActivityRunner\Worker\WorkerInterface
     */
    private function getWorker($workerName)
    {
        return $this->workerBag->get($workerName);
    }

    /**
     * Cleans up error messages
     *
     * Specifically, we might have a syntax error on /var/tmp/ABCD/index.php,
     * but we really want to just show "index.php"
     *
     * @param string $output
     * @param string $codeDirectory
     * @return string
     */
    public static function cleanError($output, $codeDirectory)
    {
        $output = str_replace($codeDirectory.'/', '', $output);
        $output = str_replace($codeDirectory, '', $output);

        // remove the stack trace so we *just* get the error
        // this might differ based on php version of installation, tbd
        $stackTracePos = strpos($output, 'PHP Stack trace');
        if ($stackTracePos !== false) {
            $output = substr($output, 0, $stackTracePos);
        }

        return trim($output);
    }

    /**
     * Gets the full list of files to be created - by looking at the file
     * builder + any input files that should override those.
     *
     * @param Activity $activity
     * @return array
     */
    private function getFilesToCreate(Activity $activity)
    {
        $challenge = $activity->getChallengeObject();
        $fileBuilder = $challenge->getFileBuilder();

        $files = array();

        // loop through *all* of the files in the builder - this should be the full set
        foreach ($fileBuilder->getFilenames() as $filename) {
            // look first to see if an input file was sent
            if ($activity->hasInputFile($filename)) {
                $files[$filename] = $activity->getInputFileContents($filename);
            } else {
                // fallback to get the original file contents
                $fileBuilder->getFileObject($filename);
            }
        }

        return $files;
    }
}
