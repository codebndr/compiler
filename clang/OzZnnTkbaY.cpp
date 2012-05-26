#include <Arduino.h>

int main(void)
{
	init();

#if defined(USBCON)
	USB.attach();
#endif
	
	setup();
    
	for (;;) {
		loop();
		if (serialEventRun) serialEventRun();
	}
        
	return 0;
}

void setup();
void loop();
#line 1 "tempfiles/OzZnnTkbaY"
void setup()
{
int bla=5 ;
	int helloWorld= 6
}

void loop()
{
	
}
