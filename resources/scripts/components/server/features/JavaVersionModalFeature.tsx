import React, { useEffect, useState } from 'react';
import { ServerContext } from '@/state/server';
import Modal from '@/components/elements/Modal';
import tw from 'twin.macro';
import Button from '@/components/elements/Button';
import setSelectedDockerImage from '@/api/server/setSelectedDockerImage';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';
import { SocketEvent, SocketRequest } from '@/components/server/events';
import Select from '@/components/elements/Select';

const JavaVersionModalFeature = () => {
    const [ visible, setVisible ] = useState(false);
    const [ loading, setLoading ] = useState(false);

    const uuid = ServerContext.useStoreState(state => state.server.data!.uuid);
    const status = ServerContext.useStoreState(state => state.status.value);
    const { clearFlashes, clearAndAddHttpError } = useFlash();
    const { connected, instance } = ServerContext.useStoreState(state => state.socket);
    let selectedVersion = 'ghcr.io/pterodactyl/yolks:java_16';

    const dockerImageList = [
        { name: 'Java 8', image: 'ghcr.io/pterodactyl/yolks:java_8' },
        { name: 'Java 9', image: 'ghcr.io/pterodactyl/yolks:java_9' },
        { name: 'Java 11', image: 'ghcr.io/pterodactyl/yolks:java_11' },
        { name: 'Java 14', image: 'ghcr.io/pterodactyl/yolks:java_14' },
        { name: 'Java 16', image: 'ghcr.io/pterodactyl/yolks:java_16' },
    ];

    useEffect(() => {
        if (!connected || !instance || status === 'running') return;

        const listener = (line: string) => {
            if (line.toLowerCase().indexOf('minecraft 1.17 requires running the server with java 16 or above') >= 0) {
                // Paper
                setVisible(true);
            } else if (line.toLowerCase().indexOf('java.lang.UnsupportedClassVersionError') >= 0) {
                // Vanilla + many others
                setVisible(true);
            } else if (line.toLowerCase().indexOf('unsupported major.minor version') >= 0) {
                // Forge
                setVisible(true);
            } else if (line.toLowerCase().indexOf('has been compiled by a more recent version of the java runtime') >= 0) {
                // Everything else?
                setVisible(true);
            }
        };

        instance.addListener(SocketEvent.CONSOLE_OUTPUT, listener);

        return () => {
            instance.removeListener(SocketEvent.CONSOLE_OUTPUT, listener);
        };
    }, [ connected, instance, status ]);

    const selectVersion = (event: { target: { value: string; }; }) => {
        selectedVersion = event.target.value;
    };

    const updateJava = () => {
        setLoading(true);
        clearFlashes('feature:javaversion');

        setSelectedDockerImage(uuid, selectedVersion)
            .then(() => {
                if (status === 'offline' && instance) {
                    instance.send(SocketRequest.SET_STATE, 'restart');
                }

                setLoading(false);
                setVisible(false);
            })
            .catch(error => {
                console.error(error);
                clearAndAddHttpError({ key: 'feature:javaversion', error });
            })
            .then(() => setLoading(false));
    };

    useEffect(() => () => {
        clearFlashes('feature:javaversion');
    }, []);

    return (
        !visible ?
            null
            :
            <Modal visible onDismissed={() => setVisible(false)} closeOnBackground={false} showSpinnerOverlay={loading}>
                <FlashMessageRender key={'feature:javaversion'} css={tw`mb-4`}/>
                <h2 css={tw`text-2xl mb-4 text-neutral-100`}>Invalid Java Version, Update Docker Image?</h2>
                <p css={tw`mt-4`}>This server is unable to start due to the required java version not being met.</p>
                <p css={tw`mt-4`}>By pressing {'"Update Docker Image"'} below you are acknowledging that the docker image this server uses will be changed to the default Java 16 image, that is provided by Pterodactyl.</p>
                <div css={tw`sm:flex items-center mt-4`}>
                    <p>Please select a Java version from the list below.</p>
                    <Select
                        onChange={selectVersion}
                    >
                        {dockerImageList.map((key, index) => {
                            return (
                                <option key={index} value={key.image}>{key.name}</option>
                            );
                        })}
                    </Select>
                </div>
                <div css={tw`mt-8 sm:flex items-center justify-end`}>
                    <Button isSecondary onClick={() => setVisible(false)} css={tw`w-full sm:w-auto border-transparent`}>
                        Cancel
                    </Button>
                    <Button onClick={updateJava} css={tw`mt-4 sm:mt-0 sm:ml-4 w-full sm:w-auto`}>
                        Update Docker Image
                    </Button>
                </div>
            </Modal>
    );
};

export default JavaVersionModalFeature;
