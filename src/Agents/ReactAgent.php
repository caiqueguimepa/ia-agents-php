<?php

namespace CaiqueBispo\AiAgentsPhp\Agents;

use CaiqueBispo\AiAgentsPhp\Agents\AgentFunction;
use CaiqueBispo\AiAgentsPhp\Agents\FunctionsOnlyAgent;

/**
 * ReactAgent
 * An agent that implements a ReAct prompting chain
 */
class ReactAgent extends FunctionsOnlyAgent {

    public string $functionRequiredMessage = "You must call one of the provided functions.";

    public bool $returnOnFunctionCall = true;   //we want to read the result each time

    // The current state of the ReAct prompting chain
    public string $state = self::STATE_INPUT;

    public int $maxFunctionCalls = 20;  //max number of function loops that can occur without more user input.


    // The stages of the ReAct prompting chain
    const STATE_INPUT = "INPUT";
    const STATE_THOUGHT = "THOUGHT";
    const STATE_ACTION = "ACTION";
    const STATE_OBSERVE = "OBSERVE";
    const STATE_COMPLETE = "COMPLETE";

    //override php function from parent
    public function ask($message) : string {
        $this->hasCalledComplete = false;

        $this->record($message); // record the message we are asking so it's in context

        $this->nextStep(); // TODO - return anything from this?

        return "";
    }

    public function nextStep() {
        switch($this->state) {
            case self::STATE_INPUT:
            case self::STATE_OBSERVE:
                return $this->thought();
            case self::STATE_THOUGHT:
                return $this->action();
            case self::STATE_ACTION:
                return $this->observe();
            case self::STATE_COMPLETE:
                return;
        }

        throw new \Exception("The agent is in an unknown state and should not be asked further questions without a reset.");
    }

    public function thought() {
        $this->state = self::STATE_THOUGHT;
        $this->chatModel->setFunctions($this->getAgentFunctions());
        $this->askFunction("recordThought");
        $this->nextStep();
    }

    public function observe() {
        $this->state = self::STATE_OBSERVE;
        $this->chatModel->setFunctions($this->getAgentFunctions());
        $this->askFunction("recordObservation");
        $this->nextStep();
    }

    public function action() {
        $this->state = self::STATE_ACTION;
        $this->chatModel->setFunctions($this->getAgentFunctions());
        $this->generate();
        $this->nextStep();
    }

    /**
     * @aiagent-description Record a thought based on the last message
     *
     * @param string $thought
     *
     * @return mixed
     */
    public function recordThought(string $thought) {
        return "recorded";
    }

    /**
     * @aiagent-description Record an observation based on the last message
     *
     * @param string $observation
     *
     * @return mixed
     */
    public function recordObservation(string $observation) {
        return "recorded";
    }

    /**
     * @aiagent-description Call this once all tasks have been completed to give a final answer
     *
     * @return void
     */
    public function completeTask() : mixed {
        parent::completeTask();
        $this->state = self::STATE_COMPLETE;
        return "finished";
    }

    public function getAgentFunctions(): array {
        switch($this->state) {
            case self::STATE_INPUT:
            case self::STATE_THOUGHT:
                return [$this->getAgentFunctionByMethodName('recordThought')];
            case self::STATE_OBSERVE:
                return [$this->getAgentFunctionByMethodName('recordObservation')];
            case self::STATE_ACTION:
                $functions = parent::getAgentFunctions();
                // remove recordThought and recordObservation from the options
                $functions = array_filter($functions, function($function) {
                    return !in_array($function->name, ['recordThought', 'recordObservation']);
                });
                return $functions;
            case self::STATE_COMPLETE:
                throw new \Exception("The agent is in a completed state and should not be asked further questions without a reset.");
        }
    }

    private function getAgentFunctionByMethodName(string $methodName) : ?AgentFunction {
        $reflector = new \ReflectionClass($this);
        $method = $reflector->getMethod($methodName);

        // We aren't doing a check if this is for agent or not... let it just be what it is
        return AgentFunction::createFromMethodReflection($method);

    }


}
